<script>
function startSafeSystemUpdate() {
    const form = document.getElementById('system_update_form');
    const targetUrl = form.getAttribute('action');
    
    const formData = $(form).serialize();

    const execBtn = document.getElementById('update_exec_btn');
    const progressContainer = document.getElementById('progress_container');
    const spinner = document.getElementById('progress_spinner');
    const statusText = document.getElementById('progress_status_text');
    const progressBar = document.getElementById('update_progress_bar');
    const percentText = document.getElementById('progress_percentage_text');

    execBtn.disabled = true;
    execBtn.classList.add('is-loading');
    execBtn.innerText = "{{ __('all.please_wait') }}";

    progressContainer.style.display = 'block';
    spinner.style.display = 'inline-block';
    statusText.classList.remove('is-error');
    statusText.innerText = "{{ __('all.preparing_system_update') }}";
    progressBar.style.width = '0%';
    progressBar.classList.remove('is-success', 'is-error');
    percentText.innerText = '0%';

    let progressInterval = setInterval(function() {
        $.ajax({
            url: '{{ route("system.update-progress") }}',
            type: 'GET',
            dataType: 'json',
            success: function(data) {
                let pct = data.progress || 0;
                let state = data.status || 'idle';

                progressBar.style.width = pct + '%';
                percentText.innerText = pct + '%';

                if (state === 'downloading_zip') {
                    statusText.innerText = "{{ __('all.downloading_update_files') }}";
                } else if (state === 'downloading_sql') {
                    statusText.innerText = "{{ __('all.downloading_database_changes') }}";
                } else if (state === 'extracting_code') {
                    statusText.innerText = "{{ __('all.extracting_program_files') }}";
                } else if (state === 'syncing_database') {
                    statusText.innerText = "{{ __('all.syncing_database') }}";
                } else if (state === 'deploying_files') {
                    statusText.innerText = "{{ __('all.deploying_new_files') }}";
                } else if (state === 'updating_dependencies') {
                    statusText.innerText = "{{ __('all.updating_dependencies') }}";
                } else if (state === 'failed') {
                    clearInterval(progressInterval);
                    spinner.style.display = 'none';

                    progressBar.classList.add('is-error');
                    statusText.classList.add('is-error');

                    let errorMsg = data.error || "{{ __('all.update_error_occurred') }}";
                    statusText.innerText = errorMsg;
                    execBtn.disabled = false;
                    execBtn.classList.remove('is-loading');
                    execBtn.innerText = "{{ __('all.nwekrdnawa') }}";
                }
            },
            error: function() {
                
            }
        });
    }, 1000);

    $.ajax({
        url: targetUrl,
        type: 'POST',
        data: formData, 
        success: function(response) {
            clearInterval(progressInterval);

            progressBar.style.width = '100%';
            progressBar.classList.add('is-success');
            percentText.innerText = '100%';
            statusText.innerText = "{{ __('all.update_completed_successfully') }}";
            spinner.style.display = 'none';

            setTimeout(function() {
                location.reload();
            }, 1500);
        },
        error: function(xhr) {
            if (xhr.status === 503) return; 

            clearInterval(progressInterval);
            spinner.style.display = 'none';

            progressBar.classList.add('is-error');
            statusText.classList.add('is-error');

            let errorMsg = "{{ __('all.update_error_occurred') }}";
            if (xhr.responseJSON && xhr.responseJSON.message) {
                errorMsg = xhr.responseJSON.message;
            }

            statusText.innerText = errorMsg;
            execBtn.disabled = false;
            execBtn.classList.remove('is-loading');
            execBtn.innerText = "{{ __('all.nwekrdnawa') }}";
        }
    });
}
</script>
