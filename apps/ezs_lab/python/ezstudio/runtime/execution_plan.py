from __future__ import annotations

from dataclasses import dataclass

from .contracts import ModulePreset, PhasePreset


class ExecutionPlanError(ValueError):
    pass


@dataclass(frozen=True)
class PlannedModule:
    position: int
    module: ModulePreset


@dataclass(frozen=True)
class ExecutionPlan:
    phase_id: str
    phase_revision: str
    phase: str
    modules: tuple[PlannedModule, ...]


class ExecutionPlanner:
    def build(self, preset: PhasePreset) -> ExecutionPlan:
        ordered = tuple(
            PlannedModule(position=i, module=module)
            for i, module in enumerate(
                sorted(preset.modules, key=lambda m: (m.order, m.id))
            )
        )
        if not ordered:
            raise ExecutionPlanError("execution_plan_empty")
        seen: set[str] = set()
        for row in ordered:
            if row.module.id in seen:
                raise ExecutionPlanError(
                    f"execution_plan_duplicate_module:{row.module.id}"
                )
            seen.add(row.module.id)
        return ExecutionPlan(
            phase_id=preset.id,
            phase_revision=preset.revision,
            phase=preset.phase,
            modules=ordered,
        )
