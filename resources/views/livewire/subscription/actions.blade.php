<div wire:init="loadRefundEligibility">
    @if (subscriptionProvider() === 'stripe')
        {{-- Plan Overview --}}
        <section class="-mt-2">
            <h3 class="pb-2">Plan Overview</h3>
            <div class="grid grid-cols-1 gap-4 xl:grid-cols-3">
                {{-- Current Plan Card --}}
                <div class="p-5 rounded border dark:bg-coolgray-100 bg-white border-neutral-200 dark:border-coolgray-400">
                    <div class="text-xs font-bold text-neutral-500 uppercase tracking-wide pb-1">Current Plan</div>
                    <div class="text-xl font-bold dark:text-warning">
                        @if (data_get(currentTeam(), 'subscription')->type() == 'dynamic')
                            Pay-as-you-go
                        @else
                            {{ data_get(currentTeam(), 'subscription')->type() }}
                        @endif
                    </div>
                    <div class="pt-2 text-sm">
                        @if (currentTeam()->subscription->stripe_cancel_at_period_end)
                            <span class="text-red-500 font-medium">Cancelling at end of period</span>
                        @else
                            <span class="text-green-500 font-medium">Active</span>
                            <span class="text-neutral-500"> &middot; Invoice
                                {{ currentTeam()->subscription->stripe_invoice_paid ? 'paid' : 'not paid' }}</span>
                        @endif
                    </div>
                </div>

                {{-- Server Limit Card --}}
                <div class="p-5 rounded border dark:bg-coolgray-100 bg-white border-neutral-200 dark:border-coolgray-400">
                    <div class="text-xs font-bold text-neutral-500 uppercase tracking-wide pb-1">Paid Servers</div>
                    <div class="text-xl font-bold dark:text-white">{{ $server_limits }}</div>
                    <div class="pt-2 text-sm text-neutral-500">Included in your plan</div>
                </div>

                {{-- Active Servers Card --}}
                <div
                    class="p-5 rounded border dark:bg-coolgray-100 bg-white border-neutral-200 dark:border-coolgray-400 {{ currentTeam()->serverOverflow() ? 'border-red-500 dark:border-red-500' : '' }}">
                    <div class="text-xs font-bold text-neutral-500 uppercase tracking-wide pb-1">Active Servers</div>
                    <div class="text-xl font-bold {{ currentTeam()->serverOverflow() ? 'text-red-500' : 'dark:text-white' }}">
                        {{ currentTeam()->servers->count() }}
                    </div>
                    <div class="pt-2 text-sm text-neutral-500">Currently running</div>
                </div>
            </div>

            @if (currentTeam()->serverOverflow())
                <x-callout type="danger" title="Server limit exceeded" class="mt-4">
                    You must delete {{ currentTeam()->servers->count() - $server_limits }} servers or upgrade your
                    subscription. Excess servers will be deactivated.
                </x-callout>
            @endif
        </section>

        {{-- Manage Plan --}}
        <section>
            <h3 class="pb-2">Manage Plan</h3>
            <div class="flex flex-col gap-3">
                <div class="flex items-center gap-4">
                    <x-forms.button class="gap-2" wire:click='stripeCustomerPortal'>
                        <svg xmlns="http://www.w3.org/2000/svg" class="w-4 h-4" fill="none" viewBox="0 0 24 24"
                            stroke-width="1.5" stroke="currentColor">
                            <path stroke-linecap="round" stroke-linejoin="round"
                                d="M2.25 8.25h19.5M2.25 9h19.5m-16.5 5.25h6m-6 2.25h3m-3.75 3h15a2.25 2.25 0 0 0 2.25-2.25V6.75A2.25 2.25 0 0 0 19.5 4.5h-15a2.25 2.25 0 0 0-2.25 2.25v10.5A2.25 2.25 0 0 0 4.5 19.5Z" />
                        </svg>
                        Manage Billing on Stripe
                    </x-forms.button>
                </div>
                <p class="text-sm text-neutral-500">Change your server quantity, update payment methods, or view
                    invoices.</p>
            </div>
        </section>

        {{-- Refund Section --}}
        @if ($refundCheckLoading)
            <section>
                <h3 class="pb-2">Refund</h3>
                <x-loading text="Checking refund eligibility..." />
            </section>
        @elseif ($isRefundEligible && !currentTeam()->subscription->stripe_cancel_at_period_end)
            <section>
                <h3 class="pb-2">Refund</h3>
                <div class="flex flex-col gap-3">
                    <div class="flex items-center gap-4">
                        <x-modal-confirmation title="Request Full Refund?" buttonTitle="Request Full Refund"
                            isErrorButton submitAction="refundSubscription"
                            :actions="[
                                'Your latest payment will be fully refunded.',
                                'Your subscription will be cancelled immediately.',
                                'All servers will be deactivated.',
                            ]" confirmationText="{{ currentTeam()->name }}"
                            confirmationLabel="Enter your team name to confirm" shortConfirmationLabel="Team Name"
                            step2ButtonText="Confirm Refund & Cancel" />
                    </div>
                    <p class="text-sm text-neutral-500">You are eligible for a full refund.
                        <strong class="dark:text-warning">{{ $refundDaysRemaining }}</strong> days remaining
                        in the 30-day refund window.</p>
                </div>
            </section>
        @elseif ($refundAlreadyUsed)
            <section>
                <h3 class="pb-2">Refund</h3>
                <p class="text-sm text-neutral-500">A refund has already been processed for this team. Each team is
                    eligible for one refund only to prevent abuse.</p>
            </section>
        @endif

        {{-- Resume / Cancel Subscription Section --}}
        @if (currentTeam()->subscription->stripe_cancel_at_period_end)
            <section>
                <h3 class="pb-2">Resume Subscription</h3>
                <div class="flex flex-col gap-3">
                    <div class="flex items-center gap-4">
                        <x-forms.button wire:click="resumeSubscription">Resume Subscription</x-forms.button>
                    </div>
                    <p class="text-sm text-neutral-500">Your subscription is set to cancel at the end of the billing
                        period. Resume to continue your plan.</p>
                </div>
            </section>
        @else
            <section>
                <h3 class="pb-2">Cancel Subscription</h3>
                <div class="flex flex-col gap-3">
                    <div class="flex flex-wrap items-center gap-2">
                        <x-modal-confirmation title="Cancel at End of Billing Period?"
                            buttonTitle="Cancel at Period End" submitAction="cancelAtPeriodEnd"
                            :actions="[
                                'Your subscription will remain active until the end of the current billing period.',
                                'No further charges will be made after the current period.',
                                'You can resubscribe at any time.',
                            ]" confirmationText="{{ currentTeam()->name }}"
                            confirmationLabel="Enter your team name to confirm"
                            shortConfirmationLabel="Team Name" step2ButtonText="Confirm Cancellation" />
                        <x-modal-confirmation title="Cancel Immediately?" buttonTitle="Cancel Immediately"
                            isErrorButton submitAction="cancelImmediately"
                            :actions="[
                                'Your subscription will be cancelled immediately.',
                                'All servers will be deactivated.',
                                'No refund will be issued for the remaining period.',
                            ]" confirmationText="{{ currentTeam()->name }}"
                            confirmationLabel="Enter your team name to confirm"
                            shortConfirmationLabel="Team Name" step2ButtonText="Permanently Cancel" />
                    </div>
                    <p class="text-sm text-neutral-500">Cancel your subscription immediately or at the end of the
                        current billing period.</p>
                </div>
            </section>
        @endif

        <div class="text-sm text-neutral-500">
            Need help? <a class="underline dark:text-white" href="{{ config('constants.urls.contact') }}"
                target="_blank">Contact us.</a>
        </div>
    @endif
</div>
