<div class="modal fade modal-on-top" id="set_price_modal" tabindex="-1" aria-hidden="true" data-backdrop="static">
    <div class="modal-dialog modal-dialog-top modal-to-fullscreen" role="document">
        <div class="modal-content">
            <div class="modal-header py-2 px-3">
                <h5 class="modal-title fs-6">{{ __('all.nrxy update krdnawa') }}</h5>
            </div>
            <div class="modal-body py-3 px-3">
                <div class="mb-3 text-right" dir="rtl">
                    <label for="modal_price" class="form-label fs-6">{{ __('all.nrxy update bo am kasa') }}</label>
                    <input type="text" class="form-control" id="modal_price" min="0" value="0" placeholder="{{ __('all.nrx') }}">
                </div>
                <div class="modal-footer justify-content-center">
                    <button type="button" class="btn btn-danger ml-3" data-dismiss="modal">
                        {{ __('all.daxstn') }}
                    </button>
                    <button type="button" class="btn btn-primary" id="confirm_and_start_update_btn">
                        {{ __('all.nwekrdnawa') }}
                    </button>
                </div>
            </div>
        </div>
    </div>
</div>

<script>
document.addEventListener("DOMContentLoaded", function() {
    // Focus on the input when modal opens
    $('#set_price_modal').on('shown.bs.modal', function() {
        $('#modal_price').trigger('focus').select();
    });

    // Handle the button click INSIDE the modal
    document.getElementById('confirm_and_start_update_btn').addEventListener('click', function() {
        const priceValue = document.getElementById('modal_price').value || 0;

        // 1. Put the price into the main form's hidden input field
        document.getElementById('hidden_update_price').value = priceValue;

        // 2. Hide the modal cleanly
        $('#set_price_modal').modal('hide');

        // 3. Fire the update function manually
        startSafeSystemUpdate();
    });
});
</script>
