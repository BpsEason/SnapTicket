<!DOCTYPE html>
<html>
<head>
    <title>SnapTicket</title>
    <style>
        body {
            font-family: sans-serif;
            display: flex;
            justify-content: center;
            align-items: center;
            min-height: 100vh;
            margin: 0;
            background-color: #f0f2f5;
            color: #333;
        }
        .container {
            text-align: center;
            padding: 30px;
            background-color: #fff;
            border-radius: 8px;
            box-shadow: 0 4px 12px rgba(0, 0, 0, 0.1);
        }
        h1 {
            color: #4CAF50;
            margin-bottom: 15px;
        }
        p {
            font-size: 1.1em;
            line-height: 1.6;
        }
    </style>
</head>
<body>
    <div class="container">
        <h1>Welcome to SnapTicket!</h1>
        <p>A high-concurrency ticket grabbing system powered by Laravel, Redis, and Swoole.</p>
        <p>Access the API at <code>http://localhost/api/ticket/grab</code></p>
    </div>
</body>
</html>
