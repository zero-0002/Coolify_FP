<?php

namespace App\Livewire\Subscription;

use App\Actions\Stripe\CancelSubscriptionAtPeriodEnd;
use App\Actions\Stripe\RefundSubscription;
use App\Actions\Stripe\ResumeSubscription;
use App\Models\Team;
use Illuminate\Support\Facades\Hash;
use Livewire\Component;
use Stripe\StripeClient;

class Actions extends Component
{
    public $server_limits = 0;

    public bool $isRefundEligible = false;

    public int $refundDaysRemaining = 0;

    public bool $refundCheckLoading = true;

    public bool $refundAlreadyUsed = false;

    public function mount(): void
    {
        $this->server_limits = Team::serverLimit();
    }

    public function loadRefundEligibility(): void
    {
        $this->checkRefundEligibility();
        $this->refundCheckLoading = false;
    }

    public function stripeCustomerPortal(): void
    {
        $session = getStripeCustomerPortalSession(currentTeam());
        redirect($session->url);
    }

    public function refundSubscription(string $password): bool|string
    {
        if (! shouldSkipPasswordConfirmation() && ! Hash::check($password, auth()->user()->password)) {
            return 'Invalid password.';
        }

        $result = (new RefundSubscription)->execute(currentTeam());

        if ($result['success']) {
            $this->dispatch('success', 'Subscription refunded successfully.');
            $this->redirect(route('subscription.index'), navigate: true);

            return true;
        }

        $this->dispatch('error', 'Something went wrong with the refund. Please <a href="'.config('constants.urls.contact').'" target="_blank" class="underline">contact us</a>.');

        return true;
    }

    public function cancelImmediately(string $password): bool|string
    {
        if (! shouldSkipPasswordConfirmation() && ! Hash::check($password, auth()->user()->password)) {
            return 'Invalid password.';
        }

        $team = currentTeam();
        $subscription = $team->subscription;

        if (! $subscription?->stripe_subscription_id) {
            $this->dispatch('error', 'Something went wrong with the cancellation. Please <a href="'.config('constants.urls.contact').'" target="_blank" class="underline">contact us</a>.');

            return true;
        }

        try {
            $stripe = new StripeClient(config('subscription.stripe_api_key'));
            $stripe->subscriptions->cancel($subscription->stripe_subscription_id);

            $subscription->update([
                'stripe_cancel_at_period_end' => false,
                'stripe_invoice_paid' => false,
                'stripe_trial_already_ended' => false,
                'stripe_past_due' => false,
                'stripe_feedback' => 'Cancelled immediately by user',
                'stripe_comment' => 'Subscription cancelled immediately by user at '.now()->toDateTimeString(),
            ]);

            $team->subscriptionEnded();

            \Log::info("Subscription {$subscription->stripe_subscription_id} cancelled immediately for team {$team->name}");

            $this->dispatch('success', 'Subscription cancelled successfully.');
            $this->redirect(route('subscription.index'), navigate: true);

            return true;
        } catch (\Exception $e) {
            \Log::error("Immediate cancellation error for team {$team->id}: ".$e->getMessage());

            $this->dispatch('error', 'Something went wrong with the cancellation. Please <a href="'.config('constants.urls.contact').'" target="_blank" class="underline">contact us</a>.');

            return true;
        }
    }

    public function cancelAtPeriodEnd(string $password): bool|string
    {
        if (! shouldSkipPasswordConfirmation() && ! Hash::check($password, auth()->user()->password)) {
            return 'Invalid password.';
        }

        $result = (new CancelSubscriptionAtPeriodEnd)->execute(currentTeam());

        if ($result['success']) {
            $this->dispatch('success', 'Subscription will be cancelled at the end of the billing period.');

            return true;
        }

        $this->dispatch('error', 'Something went wrong with the cancellation. Please <a href="'.config('constants.urls.contact').'" target="_blank" class="underline">contact us</a>.');

        return true;
    }

    public function resumeSubscription(): bool
    {
        $result = (new ResumeSubscription)->execute(currentTeam());

        if ($result['success']) {
            $this->dispatch('success', 'Subscription resumed successfully.');

            return true;
        }

        $this->dispatch('error', 'Something went wrong resuming the subscription. Please <a href="'.config('constants.urls.contact').'" target="_blank" class="underline">contact us</a>.');

        return true;
    }

    private function checkRefundEligibility(): void
    {
        if (! isCloud() || ! currentTeam()->subscription?->stripe_subscription_id) {
            return;
        }

        try {
            $this->refundAlreadyUsed = currentTeam()->subscription?->stripe_refunded_at !== null;
            $result = (new RefundSubscription)->checkEligibility(currentTeam());
            $this->isRefundEligible = $result['eligible'];
            $this->refundDaysRemaining = $result['days_remaining'];
        } catch (\Exception $e) {
            \Log::warning('Refund eligibility check failed: '.$e->getMessage());
        }
    }
}
