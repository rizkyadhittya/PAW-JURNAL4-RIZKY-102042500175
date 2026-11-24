<?php

namespace App\Http\Controllers;

use App\Models\Assignment;
use App\Models\Submission;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\StreamedResponse;

class SubmissionController extends Controller
{
    public function store(Request $request, Assignment $assignment): RedirectResponse
    {
        if (!Auth::check()) {
            return redirect()->route('login');
        }

        if (!Auth::user()->isMahasiswa()) {
            abort(403, 'Akses ditolak. Pengumpulan tugas hanya dapat dilakukan oleh Mahasiswa.');
        }

        $user = Auth::user();

        $enrollment = $assignment->course->students()->where('users.id', $user->id)->first();
        if (!$enrollment || $enrollment->pivot->status !== 'approved') {
            abort(403, 'Akses ditolak. Anda belum disetujui oleh Dosen untuk mengikuti mata kuliah tugas ini.');
        }

        if ($assignment->isOverdue()) {
            return back()->with('error', 'Gagal mengumpulkan: Batas waktu pengumpulan (deadline) tugas telah berakhir.');
        }

        $validated = $request->validate([
            'jawaban' => ['required', 'string', 'min:10', 'max:20000'],
        ], [
            'jawaban.required' => 'Teks jawaban tugas wajib diisi.',
            'jawaban.min' => 'Jawaban terlalu pendek. Tuliskan jawaban minimal 10 karakter.',
            'jawaban.max' => 'Jawaban melebihi batas maksimal 20.000 karakter.',
        ]);

        Submission::updateOrCreate(
            [
                'assignment_id' => $assignment->id,
                'user_id' => $user->id,
            ],
            [
                'jawaban' => $validated['jawaban'],
                'submitted_at' => now(),
            ]
        );

        return back()->with('success', 'Jawaban tugas Anda berhasil disimpan ke sistem.');
    }

    public function grade(Request $request, Submission $submission): RedirectResponse
    {
        // TODO 5a: Hanya dosen pengampu mata kuliah yang boleh menilai
        if ($submission->assignment->course->dosen_id !== Auth::id()) {
            abort(403, 'Akses ditolak. Anda bukan dosen pengampu mata kuliah ini.');
        }

        $validated = $request->validate([
            'nilai' => ['required', 'integer', 'min:0', 'max:100'],
            'catatan_dosen' => ['nullable', 'string', 'max:1000'],
        ], [
            'nilai.required' => 'Nilai wajib diisi.',
            'nilai.integer' => 'Nilai harus berupa angka bulat.',
            'nilai.min' => 'Nilai minimal adalah 0.',
            'nilai.max' => 'Nilai maksimal adalah 100.',
            'catatan_dosen.max' => 'Catatan dosen maksimal 1.000 karakter.',
        ]);

        $submission->update([
            'nilai' => $validated['nilai'],
            'catatan_dosen' => $validated['catatan_dosen'] ?? null,
        ]);

        $nama = $submission->user->name ?? 'mahasiswa';

        return back()->with('success', "Nilai untuk {$nama} berhasil disimpan.");
    }

    public function exportCsv(Assignment $assignment): StreamedResponse
    {
        // TODO 5b: Cek kepemilikan dosen
        if ($assignment->course->dosen_id !== Auth::id()) {
            abort(403, 'Akses ditolak. Anda bukan dosen pengampu mata kuliah ini.');
        }

        $assignment->load([
            'course.approvedStudents' => fn($q) => $q->orderBy('name'),
            'submissions',
        ]);

        $course = $assignment->course;
        $submissions = $assignment->submissions->keyBy('user_id');

        $filename = 'nilai_' . Str::slug($course->kode_mk) . '_' . Str::slug($assignment->judul) . '.csv';

        // Cegah CSV/formula injection saat dibuka di Excel
        $safe = function ($value) {
            $value = (string) $value;
            return preg_match('/^[=+\-@\t\r]/', $value) ? "'" . $value : $value;
        };

        return response()->streamDownload(function () use ($course, $assignment, $submissions, $safe) {
            $out = fopen('php://output', 'w');

            // BOM UTF-8 agar karakter dibaca benar di Excel
            fwrite($out, "\xEF\xBB\xBF");

            fputcsv($out, ['Mata Kuliah', $course->kode_mk . ' - ' . $course->nama_mk]);
            fputcsv($out, ['Tugas', $safe($assignment->judul)]);
            fputcsv($out, []);

            fputcsv($out, [
                'No', 'Nama', 'NIM', 'Email', 'Status Pengumpulan',
                'Waktu Pengumpulan', 'Nilai', 'Status Nilai', 'Catatan Dosen',
            ]);

            foreach ($course->approvedStudents as $i => $student) {
                $sub = $submissions->get($student->id);

                fputcsv($out, [
                    $i + 1,
                    $safe($student->name),
                    $student->nomor_induk,
                    $student->email,
                    $sub ? 'Sudah Mengumpulkan' : 'Belum Mengumpulkan',
                    $sub?->submitted_at?->format('d/m/Y H:i') ?? '-',
                    $sub?->nilai ?? '-',
                    $sub ? $sub->status_nilai : 'Belum Mengumpulkan',
                    $sub ? $safe($sub->catatan_dosen ?? '') : '',
                ]);
            }

            fclose($out);
        }, $filename, [
            'Content-Type' => 'text/csv; charset=UTF-8',
        ]);
    }
}