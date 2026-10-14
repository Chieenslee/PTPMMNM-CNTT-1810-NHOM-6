<!doctype html>
<html lang="vi">

<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>CommunityPass • Bài thực hành OSS</title>
    <style>
        body {
            font: 17px/1.6 Arial, sans-serif;
            margin: 0;
            background: #f4f6f8;
            color: #142235
        }

        header {
            background: #152b44;
            color: white;
            padding: 20px max(24px, calc((100vw - 920px)/2))
        }

        header strong {
            font-size: 25px
        }

        header p {
            margin: 0;
            font-size: 14px
        }

        main {
            max-width: 920px;
            margin: 24px auto;
            padding: 0 24px 28px
        }

        nav {
            margin-bottom: 20px
        }

        a {
            color: #185f95
        }

        nav a {
            margin-right: 24px
        }

        article {
            background: white;
            border: 1px solid #d9dfe6;
            padding: 15px 20px;
            border-radius: 6px;
            margin: 12px 0
        }

        form {
            background: white;
            padding: 18px;
            margin: 16px 0;
            border: 1px solid #d9dfe6;
            border-radius: 6px
        }

        input,
        select {
            font: inherit;
            padding: 7px;
            border: 1px solid #98a7b6;
            border-radius: 4px;
            margin: 5px 9px;
            max-width: 90%
        }

        button {
            font: inherit;
            background: #185f95;
            color: white;
            border: 0;
            padding: 8px 18px;
            border-radius: 4px;
            cursor: pointer
        }

        h2 {
            font-size: 24px
        }

        footer {
            border-top: 1px solid #c5d0dc;
            margin-top: 28px;
            font-size: 13px;
            color: #536373
        }

        code {
            background: #eef1f4
        }
    </style>
</head>

<body>
    <header><strong>CommunityPass</strong>
        <p>Dự án mẫu học phần Phát triển phần mềm mã nguồn mở</p>
    </header>
    <main>
        <nav><a href="/">Danh sách sự kiện</a><a href="/register.php">Đăng ký tham dự</a></nav>