<div x-data="{
    popups: {
        sponsorship: true,
        notification: true,
        realtime: false,
    },
    isDevelopment: {{ isDev() ? 'true' : 'false' }},
    init() {
        console.log(this.isDevelopment);
        this.popups.sponsorship = this.shouldShowMonthlyPopup('popupSponsorship');
        this.popups.notification = this.shouldShowMonthlyPopup('popupNotification');
        this.popups.realtime = localStorage.getItem('popupRealtime');

        let checkNumber = 1;
        let checkPusherInterval = null;
        let checkReconnectInterval = null;

        if (!this.popups.realtime) {
            checkPusherInterval = setInterval(() => {
                if (window.Echo) {
                    if (window.Echo.connector.pusher.connection.state === 'connected') {
                        this.popups.realtime = false;
                    } else {
                        checkNumber++;
                        if (checkNumber > 5) {
                            this.popups.realtime = true;
                            console.error(
                                'Coolify could not connect to its real-time service. This will cause unusual problems on the UI if not fixed! Please check the related documentation (https://coolify.io/docs/knowledge-base/cloudflare/tunnels/overview) or get help on Discord (https://coollabs.io/discord).)'
                            );
                        }

                    }
                }
            }, 2000);
        }
    },
    shouldShowMonthlyPopup(storageKey) {
        const disabledTimestamp = localStorage.getItem(storageKey);

        // If never disabled, show the popup
        if (!disabledTimestamp || disabledTimestamp === 'false') {
            return true;
        }

        // If disabled timestamp is not a valid number, show the popup
        const disabledTime = parseInt(disabledTimestamp);
        if (isNaN(disabledTime)) {
            return true;
        }

        const now = new Date();
        const disabledDate = new Date(disabledTime);

        {{-- if (this.isDevelopment) {
            // In development: check if 10 seconds have passed
            const timeDifference = now.getTime() - disabledDate.getTime();
            const tenSecondsInMs = 10 * 1000;
            return timeDifference >= tenSecondsInMs;
        } else { --}}
        // In production: check if we're in a different month or year
        const isDifferentMonth = now.getMonth() !== disabledDate.getMonth() ||
            now.getFullYear() !== disabledDate.getFullYear();
        return isDifferentMonth;
        {{-- } --}}
    }
}">
    @auth
        <span x-show="popups.realtime === true">
            @if (!isCloud())
                <x-popup>
                    <x-slot:title>
                        <span class="font-bold text-left text-red-500">WARNING: </span> Cannot connect to real-time service
                    </x-slot:title>
                    <x-slot:description>
                        <div>This will cause unusual problems on the
                            UI! <br><br>
                            Please ensure that you have opened the
                            <a class="underline" href='https://coolify.io/docs/knowledge-base/server/firewall'
                                target='_blank'>required ports</a> or get
                            help on <a class="underline" href='https://coollabs.io/discord' target='_blank'>Discord</a>.
                        </div>
                    </x-slot:description>
                    <x-slot:button-text @click="disableRealtime()">
                        Acknowledge & Disable This Popup
                    </x-slot:button-text>
                </x-popup>
            @endif
        </span>
    @endauth
    @if (instanceSettings()->is_sponsorship_popup_enabled)
        <span x-show="popups.sponsorship">
            <x-popup>
                <x-slot:title>
                    Would you like to help us to make more cool things?
                </x-slot:title>
                <x-slot:icon>
                    <img src="{{ asset('heart.png') }}" class="w-8 h-8 sm:w-12 sm:h-12 lg:w-16 lg:h-16">
                </x-slot:icon>
                <x-slot:description>
                    <div class="text-md dark:text-white">
                        <span>We are already profitable, but we would like to scale even further.</span>
                        <br><span>Please
                            consider donating on one of the following platforms.<br /><br /> <a
                                href="https://github.com/sponsors/coollabsio"
                                class="underline text-lg font-bold dark:text-white">GitHub
                                Sponsors</a> (registration required) <br /><br />
                            <a href="https://opencollective.com/coollabsio/donate?interval=month&amount=10&name=&legalName=&email="
                                class="underline text-lg font-bold dark:text-white">OpenCollective</a> (no registration
                            required)</span>
                    </div>
                </x-slot:description>
                <x-slot:button-text @click="disableSponsorship()">
                    Disable This Popup
                </x-slot:button-text>
            </x-popup>
        </span>
    @endif
    @if (currentTeam()->subscriptionPastOverDue())
        <x-banner :closable=false>
            <div><span class="font-bold text-red-500">WARNING:</span> Your subscription is in over-due. If your
                latest
                payment is not paid within a week, all automations <span class="font-bold text-red-500">will
                    be deactivated</span>. Visit <a href="{{ route('subscription.show') }}"
                    class="underline dark:text-white">/subscription</a> to check your subscription status or pay
                your
                invoice (or check your email for the invoice).
            </div>
        </x-banner>
    @endif
    @if (currentTeam()->serverOverflow())
        <x-banner :closable=false>
            <div><span class="font-bold text-red-500">WARNING:</span> The number of active servers exceeds the limit
                covered by your payment. If not resolved, some of your servers <span class="font-bold text-red-500">will
                    be deactivated</span>. Visit <a href="{{ route('subscription.show') }}"
                    class="underline dark:text-white">/subscription</a> to update your subscription or remove some
                servers.
            </div>
        </x-banner>
    @endif
    @if (!currentTeam()->isAnyNotificationEnabled())
        <span x-show="popups.notification">
            <x-popup>
                <x-slot:title>
                    No notifications enabled.
                </x-slot:title>
                <x-slot:icon>
                    <svg xmlns="http://www.w3.org/2000/svg" class="text-red-500 stroke-current w-14 h-14 shrink-0"
                        fill="none" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                            d="M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-3L13.732 4c-.77-1.333-2.694-1.333-3.464 0L3.34 16c-.77 1.333.192 3 1.732 3z" />
                    </svg>
                </x-slot:icon>
                <x-slot:description>
                    It is
                    highly recommended to enable at least
                    one
                    notification channel to receive important alerts.<br>Visit <a
                        href="{{ route('notifications.email') }}" class="underline dark:text-white">/notification</a> to
                    enable notifications.</span>
        </x-slot:description>
        <x-slot:button-text @click="disableNotification()">
            Accept and Close
        </x-slot:button-text>
        </x-popup>
        </span>
    @endif
    <script>
        function disableSponsorship() {
            // Store current timestamp instead of just 'false'
            localStorage.setItem('popupSponsorship', Date.now().toString());
        }

        function disableNotification() {
            // Store current timestamp instead of just 'false'
            localStorage.setItem('popupNotification', Date.now().toString());
        }

        function disableRealtime() {
            localStorage.setItem('popupRealtime', 'disabled');
        }
    </script>
</div>
