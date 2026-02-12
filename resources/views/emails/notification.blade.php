<!DOCTYPE html>
<html>
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>{{ $title }}</title>
    <style>
        body {
            font-family: Arial, sans-serif;
            line-height: 1.6;
            color: #333;
            max-width: 600px;
            margin: 0 auto;
            padding: 20px;
        }
        .container {
            background-color: #f9fafb;
            border-radius: 8px;
            padding: 30px;
        }
        .header {
            background-color: #4f46e5;
            color: white;
            padding: 20px;
            border-radius: 8px 8px 0 0;
            text-align: center;
        }
        .content {
            background-color: white;
            padding: 30px;
            border-radius: 0 0 8px 8px;
        }
        .button {
            display: inline-block;
            padding: 12px 24px;
            background-color: #4f46e5;
            color: white;
            text-decoration: none;
            border-radius: 6px;
            margin-top: 20px;
        }
        .footer {
            text-align: center;
            margin-top: 20px;
            color: #6b7280;
            font-size: 12px;
        }
    </style>
</head>
<body>
    <div class="container">
        <div class="header">
            <h1>{{ $title }}</h1>
        </div>
        <div class="content">
            <p>{{ $message }}</p>
            
            @if($actionUrl)
            <a href="{{ config('app.url') }}{{ $actionUrl }}" class="button">
                View Details
            </a>
            @endif
            
            <p style="margin-top: 30px; color: #6b7280; font-size: 14px;">
                This is an automated notification from your ERP system.
            </p>
        </div>
    </div>
    <div class="footer">
        <p>&copy; {{ date('Y') }} SoleSpace ERP. All rights reserved.</p>
    </div>
</body>
</html>
