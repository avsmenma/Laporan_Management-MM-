@extends('layouts.app')

@section('title', $judul ?? 'Segera Hadir')

@section('content')
<div style="background:#fff;padding:4rem 2rem;text-align:center;border-radius:12px;box-shadow:0 1px 3px rgba(0,0,0,.08);border:1px solid var(--line)">
    <div style="font-size:3rem;margin-bottom:1rem">🗂️</div>
    <h3 style="color:var(--ink-800);font-weight:700;margin:0">{{ $judul ?? 'Segera Hadir' }}</h3>
    <p style="color:var(--ink-500);margin-top:.6rem">{{ $subjudul ?? 'Laporan ini sedang disiapkan.' }}</p>
    <span class="pill pill-info" style="margin-top:1.2rem;display:inline-flex"><span class="dot"></span>Segera hadir</span>
</div>
@endsection
