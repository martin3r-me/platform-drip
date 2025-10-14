<?php

namespace Platform\Drip\Livewire;

use Livewire\Component;
use Illuminate\Validation\Rule;
use Platform\Drip\Models\Institution;
use Platform\Drip\Models\BankAccountGroup;
use Platform\Drip\Models\BankAccount;
use Platform\Core\Models\User;
use Platform\Drip\Services\GoCardlessService;
use Illuminate\Support\Facades\Redirect;

class Banks extends Component
{
    public bool $showInstitutionModal = false;
    public bool $showGroupModal = false;
    public bool $showAccountModal = false;
    
    // GoCardless Integration
    public array $gocardlessInstitutions = [];
    public string $country = 'DE';
    public string $search = '';
    public bool $loadingInstitutions = false;
    public bool $connectingBank = false;

    public array $institutionForm = [
        'name' => '',
        'country' => null,
        'external_id' => null,
    ];

    public array $groupForm = [
        'name' => '',
        'color' => null,
    ];

    public array $accountForm = [
        'name' => '',
        'iban' => '',
        'currency' => 'EUR',
        'institution_id' => null,
        'group_id' => null,
    ];

    public function mount(): void
    {
        // Lazy loading - nur laden wenn User explizit danach sucht
    }

    public function loadGoCardlessInstitutions(): void
    {
        if ($this->loadingInstitutions) return;
        
        $this->loadingInstitutions = true;
        
        try {
            /** @var User $user */
            $user = auth()->user();
            $teamId = (int) $user?->current_team_id;

            $gc = new GoCardlessService($user->id, $teamId);
            $this->gocardlessInstitutions = $gc->getInstitutions($this->country);
        } finally {
            $this->loadingInstitutions = false;
        }
    }

    public function connectBank(string $institutionId): void
    {
        if ($this->connectingBank) return;
        
        $this->connectingBank = true;
        
        try {
            /** @var User $user */
            $user = auth()->user();
            $teamId = (int) $user?->current_team_id;

            $gc = new GoCardlessService($user->id, $teamId);
            $redirectUrl = route('drip.banks.callback');
            $link = $gc->createRequisition($institutionId, $redirectUrl);

            if ($link) {
                $this->redirect($link);
            } else {
                session()->flash('error', 'Fehler beim Erstellen der Bankverbindung.');
            }
        } finally {
            $this->connectingBank = false;
        }
    }

    public function render()
    {
        /** @var User $user */
        $user = auth()->user();
        $teamId = (int) $user?->current_team_id;

        $filteredInstitutions = array_filter($this->gocardlessInstitutions, function($bank) {
            return str_contains(strtolower($bank['name']), strtolower($this->search));
        });

        return view('drip::livewire.banks', [
            'institutions' => Institution::forTeam($teamId)->orderBy('name')->get(),
            'groups' => BankAccountGroup::forTeam($teamId)->with('accounts.institution')->orderBy('name')->get(),
            'accounts' => BankAccount::forTeam($teamId)
                ->with(['institution', 'group'])
                ->orderBy('name')
                ->get(),
            'filteredInstitutions' => $filteredInstitutions,
        ])->layout('platform::layouts.app');
    }

    public function openInstitutionModal(): void { $this->resetValidation(); $this->showInstitutionModal = true; }
    public function openGroupModal(): void { $this->resetValidation(); $this->showGroupModal = true; }
    public function openAccountModal(): void { $this->resetValidation(); $this->showAccountModal = true; }

    public function saveInstitution(): void
    {
        $this->validate([
            'institutionForm.name' => ['required', 'string', 'max:255'],
            'institutionForm.country' => ['nullable', 'string', 'size:2'],
            'institutionForm.external_id' => ['nullable', 'string', 'max:255'],
        ]);

        $data = $this->institutionForm;
        Institution::create($data);
        $this->institutionForm = ['name' => '', 'country' => null, 'external_id' => null];
        $this->showInstitutionModal = false;
    }

    public function saveGroup(): void
    {
        $this->validate([
            'groupForm.name' => ['required', 'string', 'max:255'],
            'groupForm.color' => ['nullable', 'string', 'max:20'],
        ]);

        $data = $this->groupForm;
        BankAccountGroup::create($data);
        $this->groupForm = ['name' => '', 'color' => null];
        $this->showGroupModal = false;
    }

    public function saveAccount(): void
    {
        $this->validate([
            'accountForm.name' => ['required', 'string', 'max:255'],
            'accountForm.iban' => ['nullable', 'string', 'max:255'],
            'accountForm.currency' => ['required', 'string', 'size:3'],
            'accountForm.institution_id' => ['nullable', Rule::exists('drip_institutions', 'id')],
            'accountForm.group_id' => ['nullable', Rule::exists('drip_bank_account_groups', 'id')],
        ]);

        $data = $this->accountForm;
        BankAccount::create($data);
        $this->accountForm = ['name' => '', 'iban' => '', 'currency' => 'EUR', 'institution_id' => null, 'group_id' => null];
        $this->showAccountModal = false;
    }
}


