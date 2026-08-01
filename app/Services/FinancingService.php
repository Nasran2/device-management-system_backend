<?php

namespace App\Services;

use App\Models\Device;
use App\Models\DeviceFinancing;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class FinancingService
{
    public function create(Device $device, array $data): DeviceFinancing
    {
        return DB::transaction(function () use ($device, $data) {
            // The saved device price is authoritative. Never rebuild it from the
            // first payment, regular installment, or number of installments.
            $sellingCents = $this->toCents($device->selling_price);
            $firstCents = $this->toCents($data['first_payment']);
            if ($firstCents > $sellingCents) {
                throw ValidationException::withMessages(['first_payment' => 'First payment cannot exceed the selling price.']);
            }

            $count = (int) $data['number_of_installments'];
            if ($count < 1) {
                throw ValidationException::withMessages(['number_of_installments' => 'At least one installment is required.']);
            }

            $balanceCents = $sellingCents - $firstCents;
            $suggestedCents = intdiv($balanceCents + intdiv($count, 2), $count);
            $chosenCents = isset($data['installment_amount'])
                ? $this->toCents($data['installment_amount'])
                : $suggestedCents;
            if ($chosenCents < 1) {
                throw ValidationException::withMessages(['installment_amount' => 'The regular installment amount must be greater than zero.']);
            }

            $regularInstallmentCount = max(0, $count - 1);
            $regularTotalCents = $chosenCents * $regularInstallmentCount;
            if ($regularTotalCents > $balanceCents) {
                throw ValidationException::withMessages([
                    'installment_amount' => 'The regular installment amount is too high. It would make the final installment negative.',
                ]);
            }

            $finalCents = $balanceCents - $regularTotalCents;
            $adjustmentCents = $finalCents - $chosenCents;
            $finance = DeviceFinancing::create([
                'shop_id' => $device->shop_id,
                'device_id' => $device->id,
                'customer_id' => $device->customer_id,
                'selling_price' => $this->decimal($sellingCents),
                'first_payment' => $this->decimal($firstCents),
                'financed_balance' => $this->decimal($balanceCents),
                'number_of_installments' => $count,
                'payment_frequency' => $data['payment_frequency'],
                'custom_frequency_days' => $data['custom_frequency_days'] ?? null,
                'first_due_date' => $data['first_due_date'],
                'installment_amount' => $this->decimal($chosenCents),
                'suggested_installment_amount' => $this->decimal($suggestedCents),
                'final_installment_adjustment' => $this->decimal($adjustmentCents),
                'total_paid' => $this->decimal($firstCents),
                'remaining_balance' => $this->decimal($balanceCents),
            ]);

            $due = CarbonImmutable::parse($data['first_due_date']);
            for ($installmentNumber = 1; $installmentNumber <= $count; $installmentNumber++) {
                $amountCents = $installmentNumber === $count ? $finalCents : $chosenCents;
                $amount = $this->decimal($amountCents);
                $finance->installments()->create([
                    'shop_id' => $device->shop_id,
                    'device_id' => $device->id,
                    'installment_number' => $installmentNumber,
                    'due_date' => $due,
                    'expected_amount' => $amount,
                    'remaining_amount' => $amount,
                    'status' => $due->isPast() ? 'overdue' : ($due->isToday() ? 'due_today' : 'upcoming'),
                ]);
                $due = match ($data['payment_frequency']) {
                    'weekly' => $due->addWeek(),
                    'custom' => $due->addDays((int) $data['custom_frequency_days']),
                    default => $due->addMonthNoOverflow(),
                };
            }

            return $finance->load('installments');
        });
    }

    private function toCents(mixed $value): int
    {
        return (int) round((float) $value * 100, 0, PHP_ROUND_HALF_UP);
    }

    private function decimal(int $cents): string
    {
        return number_format($cents / 100, 2, '.', '');
    }
}
