"""EZStudio declarative module runtime.

This package is intentionally phase-agnostic. PROFILE is the first migration
consumer, but STEMS, CHORDS/N and LYRICS must use the same contracts.
"""

from .module_config import ModuleConfigDocument, ModuleConfigError, load_module_config
from .engine_registry import EngineAdapterRegistry, EngineAdapterError
from .decision import apply_decision

__all__ = [
    "ModuleConfigDocument",
    "ModuleConfigError",
    "load_module_config",
    "EngineAdapterRegistry",
    "EngineAdapterError",
    "apply_decision",
]
