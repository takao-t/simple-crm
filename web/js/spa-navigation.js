document.addEventListener('DOMContentLoaded', () => {
    const mainContent = document.getElementById('main-content');

    // 1. リンクのクリックをハイジャック
    document.body.addEventListener('click', (e) => {
        // Aタグ、かつターゲットが_blankでない、かつダウンロードリンクでない場合
        const link = e.target.closest('a');
        if (link && link.href && link.href.startsWith(window.location.origin) && !link.target && !link.hasAttribute('download')) {
            // WebPhoneの切断ボタンなど、JS制御のリンクは除外する必要があるかもしれません
            if (link.getAttribute('href') === '#' || link.classList.contains('no-ajax')) return;

            e.preventDefault();
            loadPage(link.href);
        }
    });

    // 2. ページ読み込み関数
    async function loadPage(url) {
        try {
            // URLに ajax_mode=1 を付与してfetch
            const fetchUrl = new URL(url);
            fetchUrl.searchParams.set('ajax_mode', '1');

            const res = await fetch(fetchUrl);

            //  リダイレクト等でログインページに飛ばされた場合を検知
            // (URLに login-crm.php が含まれていたら、強制的に通常遷移する)
            if (res.url.includes('login-crm.php')) {
                window.location.href = res.url; // 全リロード
                return;
            }

            if (!res.ok) throw new Error('Network response was not ok');
            const html = await res.text();

            // コンテンツを書き換え
            mainContent.innerHTML = html;

            // URLを更新 (履歴に追加)
            history.pushState(null, '', url);

            // 重要: 書き換えたHTMLに含まれる <script> タグは自動実行されないため、
            // 手動で再実行させる処理が必要です。
            executeScripts(mainContent);

        } catch (err) {
            console.error('Page load failed', err);
            // エラー時は通常の遷移にフォールバックしても良い
            window.location.href = url;
        }
    }

    window.spaNavigate = function(url) {
        loadPage(url);
    };

    // 3. ブラウザの「戻る/進む」ボタン対応
    window.addEventListener('popstate', () => {
        loadPage(window.location.href);
    });

    // 4. フォーム送信のハイジャック (検索ボタンなど)
    //    動的に追加されたDOMにも対応するため、document.bodyでイベント委譲
    document.body.addEventListener('submit', async (e) => {
        const form = e.target;
        // crm-form など、ページ遷移を伴うフォームのみ対象にする
        if (form.id === 'crm-form' || form.method.toUpperCase() === 'GET') {
            e.preventDefault();

            let url;
            let options = {};

            if (form.method.toUpperCase() === 'POST') {
                url = form.action;
                const formData = new FormData(form);
                // POSTの場合もレスポンスはHTML(次の画面)として受け取る
                // ただし、actionのURLに ajax_mode=1 をつける
                const postUrl = new URL(url, window.location.origin);
                postUrl.searchParams.set('ajax_mode', '1');
                
                url = postUrl.toString();
                options = {
                    method: 'POST',
                    body: formData
                };
            } else {
                // GET
                const formData = new FormData(form);
                const searchParams = new URLSearchParams(formData);
                searchParams.set('ajax_mode', '1'); // Ajaxフラグ
                
                // form.action にクエリパラメータを結合
                const actionUrl = new URL(form.action, window.location.origin);
                // 既存のパラメータとマージ
                searchParams.forEach((value, key) => actionUrl.searchParams.set(key, value));
                
                url = actionUrl.toString();
                options = { method: 'GET' };
            }

            try {
                const res = await fetch(url, options);
                const html = await res.text();
                mainContent.innerHTML = html;
                
                // POST後のURL更新（必要であれば）
                // GET検索の場合は検索パラメータ付きURLにする
                if (form.method.toUpperCase() === 'GET') {
                    const displayUrl = new URL(url);
                    displayUrl.searchParams.delete('ajax_mode');
                    history.pushState(null, '', displayUrl);
                }

                executeScripts(mainContent);

            } catch (err) {
                console.error(err);
                form.submit(); // エラー時は通常送信
            }
        }
    });

    // ヘルパー: innerHTMLで挿入されたスクリプトを実行する
    function executeScripts(container) {
        const scripts = container.querySelectorAll('script');
        scripts.forEach(oldScript => {
            const newScript = document.createElement('script');
            Array.from(oldScript.attributes).forEach(attr => newScript.setAttribute(attr.name, attr.value));
            newScript.appendChild(document.createTextNode(oldScript.innerHTML));
            oldScript.parentNode.replaceChild(newScript, oldScript);
        });
        
    }
});
