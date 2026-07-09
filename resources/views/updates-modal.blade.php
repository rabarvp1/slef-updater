<div class="modal modal-on-top fade" data-backdrop="static" id="updates_modal" tabindex="-1">

    <div class="modal-dialog modal-dialog-centered update-modal-dialog" role="document">

        <div class="modal-content update-modal-content">

            {{-- Header --}}
            <div class="update-modal-header d-flex justify-content-between align-items-start">

                <div class="pe-3">

                    <h2 class="update-modal-title">
                       {{__('all.super-cashier')}}
                    </h2>

                    <hr class="update-header-divider">

                    @if (!empty($updateData['released_at']))
                        <p class="update-modal-title-date">
                            {{ $updateData['released_at'] }}
                        </p>
                    @endif

                    <p class="update-modal-title-sub">
                        {{ __('all.call_us') }}
                    </p>

                    <p class="update-modal-call-us mb-0">
                        <i class="fas fa-phone-alt me-1"></i>

                        {{ __('all.bo nwekrdnawa peymanbn') }} :

                        @php
                            $phones = explode('-', app('system')->system_phone);
                        @endphp

                        @foreach($phones as $phone)
                            <a href="tel:{{ trim($phone) }}" 
                            class="update-modal-phone">
                                {{ trim($phone) }}
                            </a>
                            @if(!$loop->last)
                                <span class="phone-separator"> - </span>
                            @endif
                        @endforeach
                    </p>

                </div>

                <div class="logo mt-n1">
                    <img src="/images/logo/super-cashier.png" class="login-logo">
                </div>

            </div>

            {{-- Body --}}
            <div class="update-modal-body">

                @if (isset($updateData) && !empty($updateData['has_update']))

                    {{-- Version Cards --}}
                    <div class="update-version-cards row g-3 mb-4">

                        {{-- New Version --}}
                        <div class="col-6">

                            <div class="d-flex align-items-center gap-2 update-version-card">

                                <div class="update-version-icon new">
                                    <i class="fas fa-cloud-download-alt"></i>
                                </div>

                                <div class="flex-fill">
                                    <p class="update-version-label">
                                        {{ __('all.weshan nwe') }}
                                    </p>

                                    <p class="update-version-num">
                                        v{{ $updateData['new_version'] ?? '' }}
                                    </p>
                                </div>

                                @if (!empty($updateData['price']))
                                    <span class="update-price-badge">
                                        {{ $updateData['price'] }}
                                    </span>
                                @endif

                            </div>

                        </div>

                        {{-- Current Version --}}
                        <div class="col-6">

                            <div class="d-flex align-items-center gap-2 update-version-card">

                                <div class="update-version-icon cur">
                                    <i class="fas fa-desktop"></i>
                                </div>

                                <div>
                                    <p class="update-version-label">
                                        {{ __('all.weshan estaw') }}
                                    </p>

                                    <p class="update-version-num">
                                        v{{ $updateData['current_version'] ?? '' }}
                                    </p>
                                </div>

                            </div>

                        </div>

                    </div>

                    {{-- Legend --}}
                    <div class="d-flex align-items-center justify-content-between mb-2 update-legend-bar">

                        <span class="update-legend-wrap">
                            {{ __('all.chi nwe hatwe') }}
                        </span>

                        <div class="update-legend-items">

                            <span class="update-legend-new">
                                <span class="update-legend-dot update-legend-new-dot"></span>
                                {{ __('all.nwe') }}
                            </span>

                            <span class="update-legend-improve">
                                <span class="update-legend-dot update-legend-improve-dot"></span>
                                {{ __('all.bashtr') }}
                            </span>

                            <span class="update-legend-remove">
                                <span class="update-legend-dot update-legend-remove-dot"></span>
                                {{ __('all.remove') }}
                            </span>

                        </div>

                    </div>

                    {{-- Changelog --}}
                    <div class="update-changelog-scroll">

                        @php
                            $lines = array_filter(
                                explode("\n", $updateData['changelog'] ?? ''),
                                fn($l) => trim($l) !== ''
                            );

                            $i = 0;
                        @endphp

                        @forelse($lines as $line)

                            @php
                                $line = trim($line);

                                $dot = '#378ADD';
                                $badge = 'update-badge-improve';
                                $label = __('all.bashtr');

                                if (str_starts_with($line, '+')) {
                                    $dot = '#639922';
                                    $badge = 'update-badge-new';
                                    $label = __('all.nwe');
                                }  elseif (str_starts_with($line, '-')) {
                                    $dot = '#e23a3a';
                                    $badge = 'update-badge-remove';
                                    $label = __('all.remove');
                                }

                                $text = ltrim($line, '+-!* ');
                                $num = ++$i;
                            @endphp

                            <div class="update-row {{ $i % 2 !== 0 ? 'striped' : '' }}">

                                <span class="update-row-num">
                                    {{ $num }}
                                </span>

                                <span class="update-row-dot"
                                      style="background:{{ $dot }};">
                                </span>

                                <span class="update-badge {{ $badge }}">
                                    {{ $label }}
                                </span>

                                <p class="update-row-text">
                                    {{ $text }}
                                </p>

                            </div>

                        @empty

                            <p class="text-muted text-center py-4">
                                {{ __('all.no_changelog') }}
                            </p>

                        @endforelse

                    </div>
                @endif

            </div>

            {{-- Footer --}}
            <div class="d-flex justify-content-center align-items-center update-modal-footer">

                <button type="button"
                        data-dismiss="modal"
                        class="btn btn-danger border update-btn-close">

                    {{ __('all.daxstn') }}

                </button>

            </div>

        </div>

    </div>

</div>
