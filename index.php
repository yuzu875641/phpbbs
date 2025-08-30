<?php
// 環境変数からSupabaseのURLとAPIキーを取得
$SUPABASE_URL = getenv('SUPABASE_URL');
$SUPABASE_KEY = getenv('SUPABASE_KEY');

// REST APIを呼び出す関数
function callSupabaseApi($method, $table, $data = null, $query = '') {
    global $SUPABASE_URL, $SUPABASE_KEY;
    
    // 環境変数が設定されていない場合はエラーを返す
    if (!$SUPABASE_URL || !$SUPABASE_KEY) {
        http_response_code(500);
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
    $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    
    return ['status' => $http_code, 'data' => json_decode($response, true)];
}

// 非同期通信のPOSTリクエストを処理
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_SERVER['HTTP_X_REQUESTED_WITH']) && strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest') {
    $input_data = json_decode(file_get_contents('php://input'), true);
    
    $username = $input_data['username'] ?? '';
    $seed = $input_data['seed'] ?? '';
    $message = $input_data['message'] ?? '';
    $remember_me = $input_data['remember_me'] ?? false;

    // シード値をハッシュ化し、ユーザーIDを生成
    $hashed_seed = hash('sha256', $seed);
    $user_id = substr($hashed_seed, 0, 7);

    // ユーザーが存在しない場合は新規登録
    $user_check = callSupabaseApi('GET', 'users', null, 'username=eq.' . urlencode($username));
    if (empty($user_check['data'])) {
        callSupabaseApi('POST', 'users', [
            'username' => $username, 
            'role' => 'speaker',
            'hashed_seed' => $hashed_seed
        ]);
    }

    // コマンド処理
    if (strpos($message, '/topic ') === 0) {
        $new_topic = trim(substr($message, 7));
        if (!empty($new_topic)) {
            callSupabaseApi('PATCH', 'topics', ['content' => $new_topic], 'id=eq.1');
        }
    } else if ($message === '/clear') {
        callSupabaseApi('DELETE', 'posts', null, 'delete_all=true');
    } else {
        // 通常の投稿
        callSupabaseApi('POST', 'posts', [
            'username' => $username, 
            'user_id' => $user_id, 
            'message' => $message
        ]);
    }
    
    // Cookieをヘッダーで設定
    $cookie_options = [
        'expires' => $remember_me ? time() + (86400 * 30) : time() - 3600,
        'path' => '/',
        'httponly' => true, // JavaScriptからアクセス不可にする
        'samesite' => 'Strict'
    ];
    setcookie('username', $username, $cookie_options);
    setcookie('seed', $seed, $cookie_options);

    // 最新の投稿一覧を取得してJSONで返す
    $posts_data = callSupabaseApi('GET', 'posts', null, 'order=created_at.desc');
    $topic_data = callSupabaseApi('GET', 'topics');
    $current_topic = $topic_data['data'][0]['content'] ?? '今の話題';
    
    header('Content-Type: application/json');
    echo json_encode(['posts' => $posts_data['data'], 'topic' => $current_topic, 'username' => $username, 'seed' => $seed]);
    exit();
}

// 通常のGETリクエストでHTMLを生成
$saved_username = $_COOKIE['username'] ?? '';
$saved_seed = $_COOKIE['seed'] ?? '';

$posts_data_response = callSupabaseApi('GET', 'posts', null, 'order=created_at.desc');
$posts_data = $posts_data_response['data'] ?? [];

$topic_data_response = callSupabaseApi('GET', 'topics');
$topic_data = $topic_data_response['data'] ?? [];
$current_topic = $topic_data[0]['content'] ?? '今の話題';

// 投稿番号を1から連番で表示するための処理
$post_count = count($posts_data);
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
        .post-meta {
            font-weight: bold;
            display: flex;
            gap: 10px;
        }
        .post-meta span {
            padding: 2px 5px;
            border-radius: 3px;
        }
    </style>
</head>
<body>
    <h1>掲示板 <small>『<span id="current-topic"><?php echo htmlspecialchars($current_topic); ?></span>』</small></h1>

    <form id="post-form">
        <p>名前: <input type="text" name="username" value="<?php echo htmlspecialchars($saved_username); ?>" required></p>
        <p>シード値: <input type="password" name="seed" value="<?php echo htmlspecialchars($saved_seed); ?>" required></p>
        <p><input type="checkbox" name="remember_me" <?php echo isset($_COOKIE['username']) ? 'checked' : ''; ?>> 名前とパスワードを保存する</p>
        <p>メッセージ: <br><textarea name="message" rows="5" cols="40" required></textarea></p>
        <p><input type="submit" value="投稿" id="submit-btn"></p>
    </form>

    <hr>

    <h2>投稿一覧</h2>
    <div id="posts-list">
        <?php $display_id = $post_count; ?>
        <?php foreach ($posts_data as $post): ?>
            <div class="post">
                <div class="post-meta">
                    <span>No.<?php echo $display_id; ?></span>
                    <span><?php echo htmlspecialchars($post['username']); ?>@<?php echo htmlspecialchars($post['user_id'] ?? ''); ?></span>
                </div>
                <p><?php echo nl2br(htmlspecialchars($post['message'])); ?></p>
                <small>投稿日時: 
                    <?php 
                        $utc_time = new DateTime($post['created_at']);
                        $utc_time->setTimezone(new DateTimeZone('Asia/Tokyo'));
                        echo $utc_time->format('Y-m-d H:i:s');
                    ?>
                </small>
            </div>
        <?php $display_id--; ?>
        <?php endforeach; ?>
    </div>

    <script>
        document.getElementById('post-form').addEventListener('submit', function(event) {
            event.preventDefault();
            
            const submitBtn = document.getElementById('submit-btn');
            submitBtn.disabled = true;
            setTimeout(() => { submitBtn.disabled = false; }, 5000);

            const form = event.target;
            const formData = new FormData(form);
            const data = {
                username: formData.get('username'),
                seed: formData.get('seed'),
                message: formData.get('message'),
                remember_me: formData.get('remember_me') === 'on'
            };

            fetch(form.action, {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    'X-Requested-With': 'XMLHttpRequest'
                },
                body: JSON.stringify(data)
            })
            .then(response => response.json())
            .then(result => {
                if (result.posts) {
                    const postsList = document.getElementById('posts-list');
                    postsList.innerHTML = '';
                    let displayId = result.posts.length;
                    result.posts.forEach(post => {
                        const postElement = document.createElement('div');
                        postElement.className = 'post';
                        const utcTime = new Date(post.created_at);
                        const jstTime = new Date(utcTime.getTime() + 9 * 60 * 60 * 1000);
                        const formattedTime = jstTime.toISOString().slice(0, 19).replace('T', ' ');
                        
                        postElement.innerHTML = `
                            <div class="post-meta">
                                <span>No.${displayId}</span>
                                <span>${post.username}@${post.user_id || ''}</span>
                            </div>
                            <p>${post.message.replace(/\n/g, '<br>')}</p>
                            <small>投稿日時: ${formattedTime}</small>
                        `;
                        postsList.appendChild(postElement);
                        displayId--;
                    });
                }
                if (result.topic) {
                    document.getElementById('current-topic').textContent = result.topic;
                }
                
                // 投稿後のフォームをリセット
                form.querySelector('[name="message"]').value = '';
                
                // シード値が変更されていないか確認し、フォームを更新
                const seedInput = form.querySelector('[name="seed"]');
                const usernameInput = form.querySelector('[name="username"]');
                if (seedInput.value === data.seed) {
                    seedInput.value = data.seed;
                    usernameInput.value = data.username;
                }
            })
            .catch(error => {
                console.error('Error:', error);
                alert('投稿に失敗しました。');
            });
        });

        // Enterキーでの投稿を有効にする
        document.querySelector('textarea[name="message"]').addEventListener('keydown', function(e) {
            if (e.key === 'Enter' && !e.shiftKey) {
                e.preventDefault();
                document.getElementById('post-form').dispatchEvent(new Event('submit'));
            }
        });
    </script>
</body>
</html>
