<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>SAERP Replica Sync</title>
    @if (($run['status'] ?? '') === 'running')
        {{-- Auto-refresh while a restore is in flight so the result shows up. --}}
        <meta http-equiv="refresh" content="8">
    @endif
    <style>
        :root { color-scheme: light dark; }
        * { box-sizing: border-box; }
        body { font: 15px/1.5 system-ui, sans-serif; max-width: 760px; margin: 40px auto; padding: 0 20px; }
        h1 { font-size: 20px; margin-bottom: 4px; }
        .sub { color: #888; margin-top: 0; }
        .card { border: 1px solid #8884; border-radius: 10px; padding: 18px 20px; margin: 18px 0; }
        dl { display: grid; grid-template-columns: max-content 1fr; gap: 6px 16px; margin: 0; }
        dt { color: #888; }
        dd { margin: 0; font-family: ui-monospace, monospace; word-break: break-all; }
        .banner { padding: 12px 16px; border-radius: 8px; margin: 14px 0; }
        .ok { background: #1a7f3722; border: 1px solid #1a7f37; }
        .err { background: #c0392b22; border: 1px solid #c0392b; }
        .run { background: #b8860b22; border: 1px solid #b8860b; }
        label { display: block; margin: 12px 0 4px; font-weight: 600; }
        input[type=text], input[type=password] { width: 100%; padding: 9px 11px; border: 1px solid #8886; border-radius: 7px; background: transparent; color: inherit; }
        button { margin-top: 16px; padding: 11px 18px; border: 0; border-radius: 8px; background: #c0392b; color: #fff; font-weight: 600; cursor: pointer; }
        button:hover { background: #a83224; }
        .warn { color: #c0392b; font-weight: 600; }
        small { color: #888; }
    </style>
</head>
<body>
    <h1>SAERP Replica Sync</h1>
    <p class="sub">Restore the live replica from the backup <code>.sql</code>.</p>

    @if (session('ok'))    <div class="banner ok">✅ {{ session('ok') }}</div> @endif
    @if (session('error')) <div class="banner err">⛔ {{ session('error') }}</div> @endif

    {{-- Live run state --}}
    @php $run = $run ?? []; @endphp
    @if (($run['status'] ?? '') === 'running')
        <div class="banner run">⏳ <strong>Restore in progress</strong> — started {{ $run['started_at'] }}.
            Snapshotting the current replica first, then applying the backup. This page refreshes every 8s.</div>
    @elseif (($run['status'] ?? '') === 'success' && !empty($run['summary']))
        @php $s = $run['summary']; @endphp
        <div class="banner ok">✅ <strong>Last restore succeeded</strong> ({{ $run['finished_at'] }}) in {{ $s['duration_sec'] }}s.
            Restored <code>{{ $s['database'] }}</code> from the backup.<br>
            @if (!empty($s['snapshot_file']))<small>Pre-restore snapshot: <code>{{ $s['snapshot_file'] }}</code></small>@endif
        </div>
    @elseif (($run['status'] ?? '') === 'failed')
        <div class="banner err">❌ <strong>Last restore failed</strong> ({{ $run['finished_at'] }}):<br>
            <code>{{ $run['error'] }}</code></div>
    @endif

    <div class="card">
        <dl>
            <dt>Status</dt>      <dd>{{ $status['enabled'] ? 'enabled' : 'DISABLED' }}</dd>
            <dt>Database</dt>    <dd>{{ $status['database'] }} @ {{ $status['host'] }}</dd>
            <dt>Backup file</dt> <dd>{{ $status['backup_file'] }}</dd>
            <dt>Backup</dt>      <dd>{{ $status['backup_present'] ? number_format(($status['backup_bytes'] ?? 0) / 1048576, 1) . ' MB' : 'NOT FOUND' }}</dd>
            <dt>Snapshot 1st</dt><dd>{{ $status['snapshot_first'] ? 'yes → ' . $status['snapshot_dir'] : 'NO' }}</dd>
        </dl>
    </div>

    @if (($run['status'] ?? '') !== 'running')
        <div class="card">
            <p class="warn">⚠ This OVERWRITES the live, shared <code>{{ $status['database'] }}</code> database.</p>
            <p><small>The current replica is snapshotted first, but any changes other SAERP users made
                since the backup will be reverted. Treat this as a last resort.</small></p>
            <form method="POST" action="/replica/restore">
                @csrf
                <label for="confirm">Type <code>CONFIRM</code> to proceed</label>
                <input type="text" id="confirm" name="confirm" autocomplete="off" placeholder="CONFIRM">

                <label for="secret">Admin key</label>
                <input type="password" id="secret" name="secret" autocomplete="off" placeholder="REPLICA_SYNC_API_KEY">

                <button type="submit">Restore replica from backup</button>
            </form>
        </div>
    @endif
</body>
</html>
