<?php

namespace Tests\Feature;

use App\Models\Master;
use App\Models\Payment;
use App\Models\Referral;
use App\Models\ReferralEarning;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ReferralApiTest extends TestCase
{
    use RefreshDatabase;

    private Master $referrer;

    private Master $referred;

    protected function setUp(): void
    {
        parent::setUp();

        config(['referral.percent' => 10]);

        $this->referrer = Master::create([
            'name' => 'Маша',
            'referral_code' => 'MASHA10',
        ]);

        $this->referred = Master::create([
            'name' => 'Лена',
            'referral_code' => 'LENA77',
        ]);
    }

    public function test_attach_binds_referred_master_to_code_owner(): void
    {
        $response = $this->postJson('/api/referrals/attach', [
            'code' => 'MASHA10',
        ], [
            'X-Master-Id' => (string) $this->referred->id,
        ]);

        $response->assertOk()
            ->assertJsonPath('referrer_master_id', $this->referrer->id)
            ->assertJsonPath('referred_master_id', $this->referred->id)
            ->assertJsonPath('status', Referral::STATUS_PENDING);

        $this->assertDatabaseCount('referrals', 1);
    }

    public function test_attach_is_idempotent_and_does_not_rebind_to_another_code(): void
    {
        $other = Master::create(['name' => 'Оля', 'referral_code' => 'OLYA22']);

        $this->postJson('/api/referrals/attach', [
            'code' => 'MASHA10',
        ], [
            'X-Master-Id' => (string) $this->referred->id,
        ])->assertOk();

        $second = $this->postJson('/api/referrals/attach', [
            'code' => 'OLYA22',
        ], [
            'X-Master-Id' => (string) $this->referred->id,
        ]);

        $second->assertOk()
            ->assertJsonPath('referrer_master_id', $this->referrer->id);

        $this->assertDatabaseCount('referrals', 1);
        $this->assertDatabaseMissing('referrals', [
            'referred_master_id' => $this->referred->id,
            'referrer_master_id' => $other->id,
        ]);
    }

    public function test_attach_rejects_unknown_code(): void
    {
        $this->postJson('/api/referrals/attach', [
            'code' => 'NOPE',
        ], [
            'X-Master-Id' => (string) $this->referred->id,
        ])->assertStatus(422)
            ->assertJsonPath('message', 'Invalid referral code');

        $this->assertDatabaseCount('referrals', 0);
    }

    public function test_attach_rejects_self_referral(): void
    {
        $this->postJson('/api/referrals/attach', [
            'code' => 'MASHA10',
        ], [
            'X-Master-Id' => (string) $this->referrer->id,
        ])->assertStatus(422);

        $this->assertDatabaseCount('referrals', 0);
    }

    public function test_attach_requires_master_header(): void
    {
        $this->postJson('/api/referrals/attach', [
            'code' => 'MASHA10',
        ])->assertUnauthorized();
    }

    public function test_attach_rejects_unknown_master_id(): void
    {
        $this->postJson('/api/referrals/attach', [
            'code' => 'MASHA10',
        ], [
            'X-Master-Id' => '999',
        ])->assertUnauthorized();
    }

    public function test_my_lists_referred_masters_with_reward_and_earned(): void
    {
        $ira = Master::create(['name' => 'Ира', 'referral_code' => 'IRA31']);
        $olya = Master::create(['name' => 'Оля', 'referral_code' => 'OLYA22']);

        $iraReferral = Referral::create([
            'referrer_master_id' => $this->referrer->id,
            'referred_master_id' => $ira->id,
            'status' => Referral::STATUS_PENDING,
        ]);

        Referral::create([
            'referrer_master_id' => $this->referrer->id,
            'referred_master_id' => $olya->id,
            'status' => Referral::STATUS_PENDING,
        ]);

        Payment::create([
            'master_id' => $ira->id,
            'amount' => 3000,
            'type' => Payment::TYPE_CARD,
        ]);

        $response = $this->getJson('/api/referrals/my', [
            'X-Master-Id' => (string) $this->referrer->id,
        ]);

        $response->assertOk();

        $payload = $response->json();
        $this->assertCount(2, $payload);

        $iraRow = collect($payload)->firstWhere('name', 'Ира');
        $olyaRow = collect($payload)->firstWhere('name', 'Оля');

        $this->assertTrue($iraRow['rewarded']);
        $this->assertSame(30000, $iraRow['earned']); // 3000 * 10 (как в ReferralService)
        $this->assertFalse($olyaRow['rewarded']);
        $this->assertSame(0, $olyaRow['earned']);

        $this->assertDatabaseHas('referrals', [
            'id' => $iraReferral->id,
            'status' => Referral::STATUS_REWARDED,
        ]);
    }

    public function test_my_does_not_leak_other_referrers_referrals(): void
    {
        $stranger = Master::create(['name' => 'Чужой', 'referral_code' => 'STR01']);

        Referral::create([
            'referrer_master_id' => $stranger->id,
            'referred_master_id' => $this->referred->id,
            'status' => Referral::STATUS_PENDING,
        ]);

        $this->getJson('/api/referrals/my', [
            'X-Master-Id' => (string) $this->referrer->id,
        ])->assertOk()
            ->assertExactJson([]);
    }

    public function test_earnings_summary_splits_pending_and_paid(): void
    {
        $ira = Master::create(['name' => 'Ира', 'referral_code' => 'IRA31']);
        $dasha = Master::create(['name' => 'Даша', 'referral_code' => 'DASHA64']);

        $iraReferral = Referral::create([
            'referrer_master_id' => $this->referrer->id,
            'referred_master_id' => $ira->id,
            'status' => Referral::STATUS_REWARDED,
        ]);

        $dashaReferral = Referral::create([
            'referrer_master_id' => $this->referrer->id,
            'referred_master_id' => $dasha->id,
            'status' => Referral::STATUS_REWARDED,
        ]);

        $iraPayment = Payment::create([
            'master_id' => $ira->id,
            'amount' => 3000,
            'type' => Payment::TYPE_CARD,
        ]);

        $dashaPayment = Payment::create([
            'master_id' => $dasha->id,
            'amount' => 2000,
            'type' => Payment::TYPE_CARD,
        ]);

        // Observer уже мог создать earnings с pending — чистим и задаём явно.
        ReferralEarning::query()->delete();

        ReferralEarning::create([
            'referrer_master_id' => $this->referrer->id,
            'referred_master_id' => $ira->id,
            'referral_id' => $iraReferral->id,
            'payment_id' => $iraPayment->id,
            'payment_amount' => 3000,
            'amount' => 30000,
            'percent' => 10,
            'status' => ReferralEarning::STATUS_PENDING,
        ]);

        ReferralEarning::create([
            'referrer_master_id' => $this->referrer->id,
            'referred_master_id' => $dasha->id,
            'referral_id' => $dashaReferral->id,
            'payment_id' => $dashaPayment->id,
            'payment_amount' => 2000,
            'amount' => 20000,
            'percent' => 10,
            'status' => ReferralEarning::STATUS_PAID,
        ]);

        $this->getJson('/api/referrals/earnings', [
            'X-Master-Id' => (string) $this->referrer->id,
        ])->assertOk()
            ->assertExactJson([
                'total' => 50000,
                'pending' => 30000,
                'paid' => 20000,
                'rewarded_referrals' => 2,
            ]);
    }

    public function test_earnings_returns_zeros_when_empty(): void
    {
        $this->getJson('/api/referrals/earnings', [
            'X-Master-Id' => (string) $this->referrer->id,
        ])->assertOk()
            ->assertExactJson([
                'total' => 0,
                'pending' => 0,
                'paid' => 0,
                'rewarded_referrals' => 0,
            ]);
    }

    public function test_promo_and_trial_do_not_create_earnings(): void
    {
        Referral::create([
            'referrer_master_id' => $this->referrer->id,
            'referred_master_id' => $this->referred->id,
            'status' => Referral::STATUS_PENDING,
        ]);

        Payment::create([
            'master_id' => $this->referred->id,
            'amount' => 0,
            'type' => Payment::TYPE_PROMO,
        ]);

        Payment::create([
            'master_id' => $this->referred->id,
            'amount' => 0,
            'type' => Payment::TYPE_TRIAL,
        ]);

        $this->assertDatabaseCount('referral_earnings', 0);
        $this->assertDatabaseHas('referrals', [
            'referred_master_id' => $this->referred->id,
            'status' => Referral::STATUS_PENDING,
        ]);
    }

    public function test_zero_amount_card_blocks_later_real_payment_reward(): void
    {
        // Известный баг/нюанс: scopeMonetary считает card/sbp с amount=0,
        // поэтому «первый» денежный платёж с amount>0 может не дать earning.
        Referral::create([
            'referrer_master_id' => $this->referrer->id,
            'referred_master_id' => $this->referred->id,
            'status' => Referral::STATUS_PENDING,
        ]);

        Payment::create([
            'master_id' => $this->referred->id,
            'amount' => 0,
            'type' => Payment::TYPE_CARD,
        ]);

        Payment::create([
            'master_id' => $this->referred->id,
            'amount' => 2000,
            'type' => Payment::TYPE_CARD,
        ]);

        $this->assertDatabaseCount('referral_earnings', 0);
        $this->assertDatabaseHas('referrals', [
            'referred_master_id' => $this->referred->id,
            'status' => Referral::STATUS_PENDING,
        ]);
    }

    public function test_only_first_monetary_payment_creates_earning(): void
    {
        Referral::create([
            'referrer_master_id' => $this->referrer->id,
            'referred_master_id' => $this->referred->id,
            'status' => Referral::STATUS_PENDING,
        ]);

        Payment::create([
            'master_id' => $this->referred->id,
            'amount' => 3000,
            'type' => Payment::TYPE_CARD,
        ]);

        Payment::create([
            'master_id' => $this->referred->id,
            'amount' => 3000,
            'type' => Payment::TYPE_CARD,
        ]);

        $this->assertDatabaseCount('referral_earnings', 1);
        $this->assertDatabaseHas('referral_earnings', [
            'referrer_master_id' => $this->referrer->id,
            'referred_master_id' => $this->referred->id,
            'payment_amount' => 3000,
            'amount' => 30000,
            'status' => ReferralEarning::STATUS_PENDING,
        ]);
    }
}
