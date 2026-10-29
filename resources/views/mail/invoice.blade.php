{{--
    The invoice e-mail: the body the user wrote (already rendered and
    sanitized), the PDF goes as an attachment.
--}}
<!DOCTYPE html>
<html>
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
</head>
<body style="margin: 0; padding: 24px; font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Helvetica, Arial, sans-serif; font-size: 15px; line-height: 1.5;">
    <div>{!! $body !!}</div>
</body>
</html>
