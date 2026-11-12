from __future__ import annotations

from dataclasses import dataclass
from typing import Any, Callable

from .contracts import ModulePreset
from .engine_registry import EngineAdapterRegistry
from .execution_plan import ExecutionPlan, ExecutionPlanner
from .preset_loader import LoadedPreset, PhasePreset
from .provenance import plan_provenance


class AnalysisEngineError(RuntimeError):
    pass


@dataclass(frozen=True)
class ModuleExecution:
    id: str
    revision: str
    adapter: str
    result: dict[str, Any]


@dataclass(frozen=True)
class PhaseExecution:
    phase: str
    preset_id: str
    preset_revision: str
    modules: tuple[ModuleExecution, ...]
    provenance: dict[str, Any]


class AnalysisEngine:
    """Generic declarative orchestrator.

    V2A deliberately contains no phase-specific scientific policy.
    """

    RUNTIME_VERSION = "2a"

    def __init__(
        self,
        adapters: EngineAdapterRegistry,
        *,
        planner: ExecutionPlanner | None = None,
    ) -> None:
        self.adapters = adapters
        self.planner = planner or ExecutionPlanner()

    def plan(self, loaded: LoadedPreset[PhasePreset]) -> ExecutionPlan:
        return self.planner.build(loaded.value)

    def execute(
        self,
        loaded: LoadedPreset[PhasePreset],
        context_factory: Callable[[ModulePreset], Any],
    ) -> PhaseExecution:
        plan = self.plan(loaded)
        executions: list[ModuleExecution] = []
        for row in plan.modules:
            module = row.module
            if not self.adapters.has(module.adapter):
                raise AnalysisEngineError(
                    f"adapter_not_registered:{module.id}:{module.adapter}"
                )
            context = context_factory(module)
            payload = {
                "schema": "ezstudio.module-preset.v1",
                "module": {
                    "id": module.id,
                    "revision": module.revision,
                    "phase": module.phase,
                    "order": module.order,
                },
                "adapter": module.adapter,
                "engine": module.engine,
                "model": module.model,
                "device": module.device,
                "inputs": module.inputs,
                "settings": module.settings,
                "decision": module.decision,
                "outputs": module.outputs,
                "diagnostics": module.diagnostics,
            }
            result = self.adapters.execute(module.adapter, payload, context)
            executions.append(
                ModuleExecution(
                    id=module.id,
                    revision=module.revision,
                    adapter=module.adapter,
                    result=result,
                )
            )

        provenance = plan_provenance(
            plan,
            preset_sha256=loaded.sha256,
            runtime_version=self.RUNTIME_VERSION,
        )
        return PhaseExecution(
            phase=plan.phase,
            preset_id=plan.phase_id,
            preset_revision=plan.phase_revision,
            modules=tuple(executions),
            provenance=provenance,
        )
