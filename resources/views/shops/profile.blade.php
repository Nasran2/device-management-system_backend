<x-layouts.app title="Shop Profile">
    <div class="mx-auto max-w-4xl">
        <div class="mb-7">
            <p class="eyebrow">Shop settings</p>
            <h1 class="page-title">{{ $shop->name }}</h1>
            <p class="page-copy">Update contact information and customer reminder preference. Platform commission settings remain read-only.</p>
        </div>
        <form class="panel space-y-5 p-6" method="post" action="{{ route('shop-profile.update') }}">
            @csrf @method('put')
            <div class="form-grid">
                <label class="field-label">Shop name <x-required/><input class="field-input" name="name" value="{{ old('name',$shop->name) }}" required></label>
                <label class="field-label">Owner name <x-required/><input class="field-input" name="owner_name" value="{{ old('owner_name',$shop->owner_name) }}" required></label>
                <label class="field-label">Mobile <x-required/><input class="field-input" name="mobile" value="{{ old('mobile',$shop->mobile) }}" required></label>
                <label class="field-label">Alternative mobile<input class="field-input" name="alternative_mobile" value="{{ old('alternative_mobile',$shop->alternative_mobile) }}"></label>
                <label class="field-label">City<input class="field-input" name="city" value="{{ old('city',$shop->city) }}"></label>
                <label class="field-label">District<input class="field-input" name="district" value="{{ old('district',$shop->district) }}"></label>
                <label class="field-label">Platform commission <x-info text="Captured per device using the platform rate configured by the Super Admin."/><input class="field-input bg-slate-100" value="{{ $shop->commission_percentage }}% · {{ str_replace('_',' ',$shop->commission_basis) }}" disabled></label>
            </div>
            <label class="field-label">Address <x-required/><textarea class="field-input" name="address" required>{{ old('address',$shop->address) }}</textarea></label>
            <label class="field-label">Notes<textarea class="field-input" name="notes">{{ old('notes',$shop->notes) }}</textarea></label>
            <label class="flex gap-2 rounded-xl bg-slate-50 p-4"><input type="checkbox" name="reminders_enabled" value="1" @checked(old('reminders_enabled',$shop->reminders_enabled))> Enable automatic installment reminders for this shop</label>
            <button class="primary-button">Save shop profile</button>
        </form>
    </div>
</x-layouts.app>
