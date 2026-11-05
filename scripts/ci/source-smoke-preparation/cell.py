"""One finite same-runner handoff contract. No provider or Docker executor."""
from dataclasses import dataclass, field

from contract import (CellBudget, Context, EXECUTION_ENABLED, WORKFLOW, digest,
                      require, require_complete_spec, same_cell)

UPLOAD = 'actions/upload-artifact@043fb46d1a93c77aae656e7c1c64a875d1fc6a0a'
STEPS = ('admit-static-contract-and-start-guardian', 'prepare-offline-images-and-produce-report',
         'upload-compact-report', 'independently-download-and-bind-on-live-daemon',
         'collect-through-held-reviewed-packet', 'cleanup-and-independent-absence', 'retain-final-outcome')


def supported_handoff():
    """Data for review, not an added workflow job or a dispatch mechanism."""
    return {'workflow': WORKFLOW, 'wrapper': 'scripts/ci/service-mode-image-smoke.sh',
            'jobs': 1, 'same_runner': True, 'same_daemon': True, 'steps': list(STEPS),
            'upload_action': UPLOAD, 'artifact_member': 'preparation-report.json',
            'artifact_retention_days': 1, 'provider_read_scope': 'actions:read',
            'provider_authority_in_candidate': False, 'image_transport': [],
            'global_seconds': 2700, 'preparation_seconds': 1200,
            'collection_seconds': 1200, 'final_cleanup_seconds': 300,
            'execution_enabled': False}


def validate_handoff(value):
    require(value == supported_handoff(), 'Unsupported runner/daemon/provider handoff')


@dataclass
class Lifecycle:
    """Countercontrols require a guardian before even an attempted allocation.

    An in-memory proof of sequencing is not a live guardian implementation.
    The executable wrapper remains held; this object issues no allocation or
    cleanup command and cannot promote its own data into an execution grant.
    """
    context: Context
    budget: CellBudget
    stage: str = 'static'
    owned: dict = field(default_factory=dict)
    receipt_sha256: str | None = None
    deadline: float | None = None
    cleanup_deadline: float | None = None

    def arm(self, now, guardian):
        self.context.validate()
        require(self.stage == 'static' and guardian == {
            'context': self.context.report_context(), 'ready': True, 'alive': True,
            'deadline': self.budget.final_deadline()}, 'Independent guardian readiness missing')
        self.budget.allocation(now, True)
        self.stage = 'preparation'

    def allocation(self, name, identity, now, guardian_alive):
        require(self.stage in ('preparation', 'collection') and guardian_alive,
                'Allocation outside guarded phase')
        require(now < self.phase_deadline(), 'Cell phase expired')
        require(name.startswith('waterline-') and name not in self.owned
                and isinstance(identity, dict) and identity, 'Owned resource identity missing or reused')
        self.owned[name] = identity

    def phase_deadline(self):
        return self.budget.preparation_active_deadline() if self.stage == 'preparation' else self.deadline - 60

    def bind(self, receipt, spec, current_context, now):
        require(self.stage == 'preparation' and now < self.budget.preparation_deadline(), 'Stale cell binding')
        same_cell(self.context, current_context)
        require_complete_spec(spec)
        require(spec['vendor_preparation_receipt_sha256'] == digest(receipt), 'Receipt bytes differ from spec')
        self.receipt_sha256 = digest(receipt)
        self.deadline = self.budget.collection_deadline(now)
        self.stage = 'collection'

    def begin_cleanup(self, now, verified_collection):
        require(self.stage == 'collection' and self.receipt_sha256 and verified_collection is True
                and now <= self.deadline, 'Collection incomplete or expired')
        self.cleanup_deadline = min(now + 300, self.budget.final_deadline())
        self.stage = 'cleanup'

    def finish(self, now, absence, guardian_exit):
        require(self.stage == 'cleanup' and now <= self.cleanup_deadline and guardian_exit == 0,
                'Cell cleanup/guardian failure')
        require(absence == {'containers': [], 'networks': [], 'volumes': [], 'images': [], 'processes': []},
                'Owned resource survived or absence proof is incomplete')
        self.stage = 'complete'


def execute_cell(*_args, **_kwargs):
    require(EXECUTION_ENABLED, 'HELD: no preparation, provider request, allocation or collection is admitted')
    # Live resource readers and the independent guardian need their own full
    # executable-source review. No declarative proof is an authority substitute.
    raise ValueError('HELD: no live cell executor has source acceptance')
