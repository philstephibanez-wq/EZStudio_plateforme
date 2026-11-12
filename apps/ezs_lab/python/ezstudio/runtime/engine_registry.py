from __future__ import annotations

from dataclasses import dataclass
from typing import Any, Callable, Protocol


class EngineAdapterError(RuntimeError):
    pass


class EngineAdapter(Protocol):
    def __call__(self, spec: dict[str, Any], context: Any) -> dict[str, Any]: ...


@dataclass(frozen=True)
class RegisteredAdapter:
    name: str
    fn: EngineAdapter


class EngineAdapterRegistry:
    """Phase-agnostic registry of engine/model adapters.

    A Module Config points to an adapter by stable name. The adapter owns only the
    technical inference capability; scientific choices remain in the Module Config.
    """

    def __init__(self) -> None:
        self._adapters: dict[str, RegisteredAdapter] = {}

    def register(self, name: str, fn: EngineAdapter) -> "EngineAdapterRegistry":
        key = name.strip().casefold()
        if not key:
            raise EngineAdapterError("adapter_name_required")
        if key in self._adapters:
            raise EngineAdapterError(f"adapter_already_registered:{name}")
        self._adapters[key] = RegisteredAdapter(name=name, fn=fn)
        return self

    def has(self, name: str) -> bool:
        return name.strip().casefold() in self._adapters

    def execute(self, name: str, spec: dict[str, Any], context: Any) -> dict[str, Any]:
        key = name.strip().casefold()
        adapter = self._adapters.get(key)
        if adapter is None:
            raise EngineAdapterError(f"adapter_not_registered:{name}")
        result = adapter.fn(spec, context)
        if not isinstance(result, dict):
            raise EngineAdapterError(f"adapter_invalid_result:{name}")
        return result
