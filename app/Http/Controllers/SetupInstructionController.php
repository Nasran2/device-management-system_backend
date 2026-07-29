<?php

namespace App\Http\Controllers;

use App\Models\DeviceSetupInstruction;
use App\Services\SetupInstructionCatalog;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class SetupInstructionController extends Controller
{
    public function __construct(private SetupInstructionCatalog $catalog) {}

    public function index(Request $request)
    {
        abort_unless($request->user()->isSuperAdmin(), 403);
        $os = $request->string('computer_os')->value() ?: 'windows';
        $brand = $request->string('phone_brand')->value() ?: 'samsung';
        $brand = $this->catalog->normalizeBrand($brand);
        $instructions = $this->catalog->for($os, $brand);

        return view('setup.instructions.index', [
            'instructions' => $instructions,
            'oses' => SetupInstructionCatalog::OSES,
            'brands' => SetupInstructionCatalog::BRANDS,
            'os' => $os,
            'brand' => $brand,
        ]);
    }

    public function edit(Request $request, DeviceSetupInstruction $instruction)
    {
        abort_unless($request->user()->isSuperAdmin(), 403);
        return view('setup.instructions.edit', compact('instruction'));
    }

    public function update(Request $request, DeviceSetupInstruction $instruction)
    {
        abort_unless($request->user()->isSuperAdmin(), 403);
        $data = $request->validate([
            'title' => ['required', 'string', 'max:255'],
            'short_description' => ['required', 'string'],
            'why_required' => ['required', 'string'],
            'action_location' => ['required', 'string', 'max:80'],
            'numbered_instructions_text' => ['required', 'string'],
            'shell_type' => ['nullable', 'string', 'max:40'],
            'command' => ['nullable', 'string'],
            'run_from' => ['nullable', 'string', 'max:255'],
            'terminal_help' => ['nullable', 'string'],
            'expected_output' => ['required', 'string'],
            'possible_errors_json' => ['required', 'json'],
            'troubleshooting_json' => ['required', 'json'],
            'verification_items_text' => ['required', 'string'],
            'confirmation_items_text' => ['nullable', 'string'],
            'server_check_key' => ['nullable', 'string', 'max:80'],
            'active' => ['nullable', Rule::in(['1'])],
            'screenshot' => ['nullable', 'image', 'max:4096'],
        ]);
        $screenshotPath = $instruction->screenshot_path;
        if ($request->hasFile('screenshot')) {
            $screenshotPath = $request->file('screenshot')->store('setup-instructions', 'public');
        }
        $lines = fn (?string $value) => collect(preg_split('/\R/', (string) $value))->map(fn ($line) => trim($line))->filter()->values()->all();
        $instruction->update([
            'title' => $data['title'],
            'short_description' => $data['short_description'],
            'why_required' => $data['why_required'],
            'action_location' => $data['action_location'],
            'numbered_instructions' => $lines($data['numbered_instructions_text']),
            'shell_type' => $data['shell_type'] ?? null,
            'command' => $data['command'] ?? null,
            'run_from' => $data['run_from'] ?? null,
            'terminal_help' => $data['terminal_help'] ?? null,
            'expected_output' => $data['expected_output'],
            'possible_errors' => json_decode($data['possible_errors_json'], true, 512, JSON_THROW_ON_ERROR),
            'troubleshooting' => json_decode($data['troubleshooting_json'], true, 512, JSON_THROW_ON_ERROR),
            'verification_items' => $lines($data['verification_items_text']),
            'confirmation_items' => $lines($data['confirmation_items_text'] ?? ''),
            'server_check_key' => $data['server_check_key'] ?? null,
            'active' => $request->boolean('active'),
            'screenshot_path' => $screenshotPath,
            'updated_by' => $request->user()->id,
        ]);

        return redirect()->route('setup-instructions.index', ['computer_os' => $instruction->computer_os, 'phone_brand' => $instruction->phone_brand])
            ->with('success', 'Structured setup instruction updated.');
    }

    public function sync(Request $request)
    {
        abort_unless($request->user()->isSuperAdmin(), 403);
        $data = $request->validate([
            'computer_os' => ['required', Rule::in(array_keys(SetupInstructionCatalog::OSES))],
            'phone_brand' => ['required', Rule::in(array_keys(SetupInstructionCatalog::BRANDS))],
            'overwrite' => ['nullable', 'boolean'],
        ]);
        $this->catalog->syncDefaults($data['computer_os'], $data['phone_brand'], $request->user()->id, $request->boolean('overwrite'));
        return redirect()->route('setup-instructions.index', $data)->with('success', $request->boolean('overwrite') ? 'Default instructions restored.' : 'Missing default instructions generated.');
    }
}
