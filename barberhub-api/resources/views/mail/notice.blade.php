<!DOCTYPE html>
<html lang="{{ app()->getLocale() }}">
<body style="margin:0;padding:24px;background:#ffffff;color:#1a1a1a;font-family:Arial,Helvetica,sans-serif;font-size:16px;line-height:1.5;">
    <div style="max-width:520px;margin:0 auto;border:1px solid #d9d9d9;padding:24px;">
        @if ($logoUrl !== null)
            <p style="margin:0 0 16px 0;"><img src="{{ $logoUrl }}" alt="{{ $barbershop }}" height="56" style="max-height:56px;"></p>
        @endif
        <p style="margin:0 0 16px 0;">{{ $greeting }}</p>
        @foreach ($lines as $line)
            <p style="margin:0 0 12px 0;">{{ $line }}</p>
        @endforeach
        @if ($actionUrl !== null)
            <p style="margin:16px 0 12px 0;"><a href="{{ $actionUrl }}" style="display:inline-block;padding:12px 20px;border:1px solid #1a1a1a;background:#1a1a1a;color:#ffffff;text-decoration:none;">{{ $actionLabel }}</a></p>
            <p style="margin:0 0 12px 0;font-size:13px;color:#595959;word-break:break-all;">{{ $actionUrl }}</p>
        @endif
        <p style="margin:24px 0 0 0;font-size:13px;color:#595959;">{{ $footer }}</p>
    </div>
</body>
</html>
