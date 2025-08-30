<?php
// 環境変数からSupabaseのURLとAPIキーを取得
$SUPABASE_URL = getenv('SUPABASE_URL');
$SUPABASE_KEY = getenv('SUPABASE_KEY');

// setcookie()はHTML出力前に実行する必要があるため、POSTリクエストの処理を最初に配置
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $username = $_POST['username'] ?? '';
    $seed = $_POST['seed'] ?? '';
    $message = $_POST['message'] ?? '';
    $remember_me = isset($_POST['remember_me']);

    if (!empty($username) && !empty($seed) && !empty($message)) {
        // シード値をSHA-256でハッシュ化し、最初の7文字をユーザーIDとする
        $hashed_seed = hash('sha256', $seed);
        $user_id = substr($hashed_seed, 0, 7);

        // ユーザーが既に存在するかチェック
        $user_check = callSupabaseApi('GET', 'users', null, 'username=eq.' . urlencode($username));
        $user_exists = !empty($user_check);

        // ユーザーが存在しない場合は新規登録
        if (!$user_exists) {
            callSupabaseApi('POST', 'users', [
                'username' => $username, 
                'role' => 'speaker',
                'hashed_seed' => $hashed_seed
            ]);
        }
        
        // 「名前とパスワードを保存する」がチェックされていたらCookieに保存
        if ($remember_me) {
            setcookie('username', $username, time() + (86400 * 30), "/"); // 30日間有効
            setcookie('hashed_seed', $hashed_seed, time() + (86400 * 30), "/"); // 30日間有効
        } else {
            // チェックが外れている場合はCookieを削除
            setcookie('username', '', time() - 3600, "/");
            setcookie('hashed_seed', '', time() - 3600, "/");
        }

        // コマンドのチェック
        if (strpos($message, '/topic ') === 0) {
            $new_topic = trim(substr($message, 7));
            if (!empty($new_topic)) {
                // /topicコマンドを処理
                callSupabaseApi('PATCH', 'topics', ['content' => $new_topic], 'id=eq.1');
            }
        } else if ($message === '/clear') {
            // /clearコマンドを処理
            callSupabaseApi('DELETE', 'posts', null, 'delete_all=true');
        } else {
            // 通常の投稿をSupabaseに挿入
            callSupabaseApi('POST', 'posts', [
                'username' => $username, 
                'user_id' => $user_id, 
                'message' => $message
            ]);
        }
    }
    
    // 投稿後にリダイレクト
    header("Location: " . $_SERVER['PHP_SELF']);
    exit();
}

// REST APIを呼び出す関数
function callSupabaseApi($method, $table, $data = null, $query = '') {
    global $SUPABASE_URL, $SUPABASE_KEY;
    
    // 環境変数が設定されていない場合はエラーを返す
    if (!$SUPABASE_URL || !$SUPABASE_KEY) {
        die("Supabase環境変数が設定されていません。");
    }

    $url = "$SUPABASE_URL/rest/v1/$table?$query";
    $ch = curl_init($url);
    
    $headers = [
        'apikey: ' . $SUPABASE_KEY,
        'Authorization: Bearer ' . $SUPABASE_KEY,
        'Content-Type: application/json',
        'Prefer: return=representation'
    ];
    
    if ($method === 'POST') {
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($data));
    } elseif ($method === 'PATCH') {
        curl_setopt($ch, CURLOPT_CUSTOMREQUEST, 'PATCH');
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($data));
    } elseif ($method === 'DELETE') {
        curl_setopt($ch, CURLOPT_CUSTOMREQUEST, 'DELETE');
    }
    
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
    
    $response = curl_exec($ch);
    curl_close($ch);
    return json_decode($response, true);
}

// Cookieからユーザー情報を取得
$saved_username = $_COOKIE['username'] ?? '';
$saved_hashed_seed = $_COOKIE['hashed_seed'] ?? '';

// 投稿と話題をSupabaseから取得
$posts_data = callSupabaseApi('GET', 'posts', null, 'order=created_at.desc');
$topic_data = callSupabaseApi('GET', 'topics');
$current_topic = $topic_data[0]['content'] ?? '今の話題';
?>

<!doctype html>
<html lang="ja">
<head>
    <meta charset="utf-8">
    <title>PHP/Supabase 掲示板</title>
    <style>
        body { font-family: sans-serif; }
        .post { border: 1px solid #ccc; padding: 10px; margin-bottom: 10px; }
        h1 small { color: #555; font-size: 0.5em; }
    </style>
</head>
<body>
    <h1>掲示板 <small>『<?php echo htmlspecialchars($current_topic); ?>』</small></h1>

    <form action="" method="post">
        <p>名前: <input type="text" name="username" value="<?php echo htmlspecialchars($saved_username); ?>" required></p>
        <p>シード値: <input type="password" name="seed" required></p>
        <p><input type="checkbox" name="remember_me" <?php echo isset($_COOKIE['username']) ? 'checked' : ''; ?>> 名前とパスワードを保存する</p>
        <p>メッセージ: <br><textarea name="message" rows="5" cols="40" required></textarea></p>
        <p><input type="submit" value="投稿"></p>
    </form>

    <hr>

    <h2>投稿一覧</h2>
    <?php foreach ($posts_data as $post): ?>
        <div class="post">
            <p>
                <strong><?php echo htmlspecialchars($post['id']); ?></strong>
                　<strong><?php echo htmlspecialchars($post['username']); ?></strong>
                @<?php echo htmlspecialchars($post['user_id'] ?? ''); ?>
                　<?php echo nl2br(htmlspecialchars($post['message'])); ?>
            </p>
            <small>投稿日時: 
                <?php 
                    $utc_time = new DateTime($post['created_at']);
                    $utc_time->setTimezone(new DateTimeZone('Asia/Tokyo'));
                    echo $utc_time->format('Y-m-d H:i:s');
                ?>
            </small>
        </div>
    <?php endforeach; ?>
</body>
</html>
