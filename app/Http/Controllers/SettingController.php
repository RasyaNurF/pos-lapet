<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Http\Requests\UpdateSettingsRequest;
use App\Services\AuditLogger;
use App\Services\SettingService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\View\View;

class SettingController extends Controller
{
    public function __construct(
        private readonly SettingService $settings,
        private readonly AuditLogger $audit,
    ) {}

    public function index(): View
    {
        return view('settings.index', [
            'groups' => config('settings.groups', []),
            'items' => config('settings.items', []),
            'values' => $this->settings->all(),
        ]);
    }

    public function update(UpdateSettingsRequest $request): RedirectResponse
    {
        /*
         * validated() mengembalikan key datar persis seperti rules — cocok
         * untuk SettingService yang juga bekerja dengan key datar.
         */
        $data = $request->validated();
        unset($data['logo'], $data['remove_logo']);

        $before = $this->settings->all();

        if ($request->hasFile('logo')) {
            $data['company_logo'] = $this->storeLogo($request->file('logo'));
        } elseif ($request->boolean('remove_logo')) {
            // Hapus filenya juga — hanya mengosongkan kolom membuat berkas
            // yatim menumpuk di disk tanpa pernah terlihat siapa pun.
            $this->deleteLogo();
            $data['company_logo'] = '';
        }

        $this->settings->set($data);

        $this->audit->log('settings.update', null, null, $this->changesSince($before));

        return redirect()
            ->route('settings.index')
            ->with('success', 'Pengaturan toko berhasil disimpan.');
    }

    /**
     * Simpan logo baru ke disk publik, lalu buang logo lama.
     *
     * Nama file sengaja acak (hashName) — memakai nama tetap membuat browser
     * menyajikan cache logo lama setelah diganti.
     */
    private function storeLogo(UploadedFile $file): string
    {
        $path = (string) $file->storeAs('settings', $file->hashName(), 'public');

        $this->deleteLogo();

        return $path;
    }

    /**
     * Buang berkas logo lama bila masih ada.
     */
    private function deleteLogo(): void
    {
        $old = (string) $this->settings->get('company_logo', '');

        if ($old !== '' && Storage::disk('public')->exists($old)) {
            Storage::disk('public')->delete($old);
        }
    }

    /**
     * Kumpulkan hanya pengaturan yang benar-benar berubah, dalam bentuk
     * from/to — lebih berguna daripada menyalin seluruh konfigurasi ke audit.
     *
     * @return array<string, array{from: mixed, to: mixed}>
     */
    private function changesSince(array $before): array
    {
        $changed = [];

        foreach ($this->settings->all() as $key => $value) {
            if (($before[$key] ?? null) !== $value) {
                $changed[$key] = ['from' => $before[$key] ?? null, 'to' => $value];
            }
        }

        return $changed;
    }
}
