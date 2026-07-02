@if(config('self-updater.enabled', true))
<div class="system-update-box">

<form id="system_update_form" action="{{ route('system.update') }}" method="POST" class="d-flex justify-content-between align-items-center mb-4">
    @csrf
    <input type="hidden" name="set_price" id="hidden_update_price" value="0">

    <label class="form-label fw-bold mb-0 update-label">
        {{ __('all.check_and_run_system_update') }}
    </label>

    <button type="button" class="btn px-4 py-2 fw-bold text-white transition-all update-btn" id="update_exec_btn" data-toggle="modal" data-target="#set_price_modal">
        {{ __('all.nwekrdnawa') }}
    </button>
</form>

    <hr class="update-divider">

    <div id="progress_container" class="update-progress-box" style="display: none;">
        <div class="d-flex justify-content-between align-items-center mb-3">
            <div class="d-flex align-items-center gap-2">
                <div class="spinner-border text-success spinner-border-sm update-spinner" id="progress_spinner" role="status"></div>
                <span id="progress_status_text" class="fw-bold update-status-text">{{ __('all.preparing_system_update') }}</span>
            </div>
            <span id="progress_percentage_text" class="fw-extrabold update-percentage">0%</span>
        </div>

        <div class="progress update-progress-track">
            <div id="update_progress_bar" class="progress-bar progress-bar-striped progress-bar-animated update-progress-fill"
                role="progressbar" style="width: 0%;">
            </div>
        </div>
    </div>
</div>
@endif
