<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\Notification;
use App\Models\NotificationRead;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/** Inbox notifikasi (F8a-4). Read-state per-user; scope tenant+outlet dari JWT. */
class NotificationController extends Controller
{
    private const MAX_INBOX = 100;

    public function index(Request $request): JsonResponse
    {
        $userId = $this->userId($request);

        $rows = DB::table('notifications as n')
            ->leftJoin('notification_reads as r', function ($join) use ($userId) {
                $join->on('r.notification_id', '=', 'n.id')->where('r.user_id', '=', $userId);
            })
            ->where('n.tenant_id', $this->tenantId($request))
            ->where('n.outlet_id', $this->outletId($request))
            // Penyaringan audiens ditegakkan DI SINI, bukan di layar. Notifikasi
            // pembatalan melaporkan tindakan kasir kepada owner; kalau ia tetap
            // dikirim ke browser lalu disembunyikan di tampilan, orang yang
            // dilaporkan tinggal membuka DevTools untuk membacanya.
            ->where(function ($q) use ($request) {
                $q->where('n.audience', Notification::UNTUK_SEMUA);

                if ($this->peran($request) === Notification::UNTUK_OWNER) {
                    $q->orWhere('n.audience', Notification::UNTUK_OWNER);
                }
            })
            ->orderByDesc('n.created_at')
            ->limit(self::MAX_INBOX)
            ->get(['n.id', 'n.type', 'n.severity', 'n.title', 'n.body', 'n.payload', 'n.created_at', 'r.read_at']);

        $data = $rows->map(fn ($row) => [
            'id' => $row->id,
            'type' => $row->type,
            'severity' => $row->severity,
            'title' => $row->title,
            'body' => $row->body,
            'payload' => $row->payload !== null ? json_decode($row->payload, true) : null,
            'created_at' => $row->created_at,
            'read' => $row->read_at !== null,
        ])->all();

        return response()->json(['data' => $data]);
    }

    public function unreadCount(Request $request): JsonResponse
    {
        return response()->json(['unread' => $this->unreadQuery($request)->count()]);
    }

    public function markRead(Request $request, string $id): JsonResponse
    {
        $notification = Notification::query()
            ->where('tenant_id', $this->tenantId($request))
            ->where('outlet_id', $this->outletId($request))
            // Ikut disaring: menjawab 404 untuk notifikasi yang tak boleh ia
            // lihat, alih-alih 200, membuat kasir tak bisa memastikan
            // keberadaannya dengan menebak id.
            ->untukPeran($this->peran($request))
            ->find($id);

        if ($notification === null) {
            return response()->json(['message' => 'Notifikasi tidak ditemukan.'], 404);
        }

        // firstOrCreate = idempoten; unique(notification_id,user_id) jadi pagar.
        NotificationRead::firstOrCreate(
            ['notification_id' => $notification->id, 'user_id' => $this->userId($request)],
            ['read_at' => now()],
        );

        return response()->json(['data' => ['id' => $notification->id, 'read' => true]]);
    }

    public function readAll(Request $request): JsonResponse
    {
        $userId = $this->userId($request);
        $now = now();

        $rows = $this->unreadQuery($request)->pluck('id')
            ->map(fn ($id) => [
                'id' => (string) Str::uuid(),
                'notification_id' => $id,
                'user_id' => $userId,
                'read_at' => $now,
            ])->all();

        if ($rows !== []) {
            // insertOrIgnore, bukan insert: daftar belum-dibaca dibaca lebih
            // dulu, barisnya ditulis sesudah. Kasir yang membuka inbox di HP
            // dan tablet sekaligus membuat dua permintaan sama-sama membawa
            // daftar yang sama, dan yang kedua menabrak unique(notification_id,
            // user_id) — ditandai-baca dua kali seharusnya tak berarti apa-apa,
            // bukan berarti layar galat.
            NotificationRead::insertOrIgnore($rows);
        }

        return response()->json(['data' => ['marked' => count($rows)]]);
    }

    /** Notifikasi outlet yang belum dibaca user ini. */
    private function unreadQuery(Request $request)
    {
        $userId = $this->userId($request);

        return Notification::query()
            ->where('tenant_id', $this->tenantId($request))
            ->where('outlet_id', $this->outletId($request))
            // Angka lonceng harus menghitung persis apa yang akan terlihat saat
            // daftarnya dibuka. Tanpa saringan yang sama, kasir melihat "1
            // belum dibaca" lalu membuka inbox kosong — dan sejak itu ia tahu
            // ada sesuatu yang disembunyikan darinya.
            ->untukPeran($this->peran($request))
            ->whereDoesntHave('reads', fn ($q) => $q->where('user_id', $userId));
    }

    /** Peran dari klaim JWT, dipasang AuthenticateJwt. */
    private function peran(Request $request): ?string
    {
        $peran = $request->attributes->get('role');

        return is_string($peran) ? $peran : null;
    }

    private function userId(Request $request): string
    {
        return (string) $request->attributes->get('user_id');
    }
}
