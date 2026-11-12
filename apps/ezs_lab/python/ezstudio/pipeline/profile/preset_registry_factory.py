from __future__ import annotations
import sys
import importlib, os
from pathlib import Path

_EZSTUDIO_PROFILE_ROOT = Path(__file__).resolve().parent
_EZSTUDIO_PYTHON_ROOT = Path(__file__).resolve().parents[3]

for _bootstrap_path in (
    _EZSTUDIO_PROFILE_ROOT,
    _EZSTUDIO_PYTHON_ROOT,
):
    if str(_bootstrap_path) not in sys.path:
        sys.path.insert(0, str(_bootstrap_path))
from typing import Any
from module import ModuleConfig
from module_registry import ModuleRegistry
from ezstudio.runtime.execution_plan import ExecutionPlanner
from ezstudio.runtime.preset_loader import LoadedPreset, PhasePreset
from ezstudio.runtime.module_config import load_module_config

def _resolve(v:Any, roots:dict[str,Path])->Any:
    if isinstance(v,list): return [_resolve(x,roots) for x in v]
    if not isinstance(v,dict): return v
    if "$env_int" in v:
        n=str(v["$env_int"]); x=int(os.getenv(n,str(v.get("default",0))))
        if "min" in v: x=max(int(v["min"]),x)
        return x
    if "$env_path" in v:
        x=os.getenv(str(v["$env_path"]))
        return x if x else _resolve(v["default"],roots)
    if "$path" in v:
        a=v["$path"]; r=roots.get(str(a[0]))
        if r is None: raise ValueError(f"runtime_root_not_registered:{a[0]}")
        return str(r.joinpath(*[str(x) for x in a[1:]]))
    return {str(k):_resolve(x,roots) for k,x in v.items()}

def _callable(ref:str):
    if ":" not in ref: raise ValueError(f"callable_reference_invalid:{ref}")
    mod,attr=ref.split(":",1)
    if not (mod=="modules" or mod.startswith("modules.")):
        raise ValueError(f"callable_module_not_allowed:{mod}")
    fn=getattr(importlib.import_module(mod),attr,None)
    if not callable(fn): raise ValueError(f"callable_not_found:{ref}")
    return fn

def build_registry_from_phase_preset(
    loaded:LoadedPreset[PhasePreset], *,
    ai_models_root:Path, profile_models_root:Path
)->ModuleRegistry:
    if loaded.value.phase!="profile":
        raise ValueError(f"profile_phase_required:{loaded.value.phase}")
    roots={"ai_models_root":ai_models_root.resolve(),
           "profile_models_root":profile_models_root.resolve()}
    reg=ModuleRegistry()
    # A module config is resolved exactly once, before running any scientific module.
    # It is immutable for this run and is also used by the SQLite capture manifest.
    preset_root = loaded.path.parent.parent
    for row in ExecutionPlanner().build(loaded.value).modules:
        m=row.module
        ref=str(m.implementation.get("callable") or "")
        module_config_ref = m.implementation.get("module_config")
        resolved_config = None
        if m.id == "semantic.clap-open-vocabulary" and not module_config_ref:
            raise ValueError("profile_clap_module_config_reference_required")
        if module_config_ref is not None:
            if not isinstance(module_config_ref, str) or not module_config_ref:
                raise ValueError("module_config_reference_invalid")
            candidate = (preset_root / module_config_ref).resolve()
            try:
                candidate.relative_to(preset_root.resolve())
            except ValueError as exc:
                raise ValueError("module_config_outside_preset_root") from exc
            resolved_config = load_module_config(candidate)
            if resolved_config.module_id != m.id or resolved_config.phase != m.phase:
                raise ValueError("module_config_identity_mismatch")
            if m.id == "semantic.clap-open-vocabulary" and resolved_config.engine_adapter != "clap-zero-shot":
                raise ValueError("profile_clap_module_adapter_mismatch")
        reg.add(ModuleConfig(
            module_id=m.id,display_name=m.display_name,family=m.family,
            order=m.order,engine=m.engine,engine_version=m.engine_version,
            model_id=m.model,
            model_path=(str(_resolve(m.model_path,roots)) if m.model_path is not None else None),
            device=m.device,
            parameters=_resolve(m.parameters,roots),
            thresholds=_resolve(m.thresholds,roots),
            weights=_resolve(m.weights,roots),
            resolved_module_config=resolved_config,
        ),_callable(ref))
    return reg
