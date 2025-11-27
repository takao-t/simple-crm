PATH := /usr/local/go/bin:$PATH
BIN_NAME := popup-notifier
CONF_NAME := popup-notifier.conf
SERVICE_NAME := popup-notifier.service
ETC_DIR := /usr/local/etc
SYSTEMD_DIR := /etc/systemd/system

# デフォルトターゲット
all: build

# 実行可能なバイナリをコンパイルする
build:
	go build -o $(BIN_NAME) .

# システム全体にアプリケーションをインストールする（root権限が必要）
install: build
	# 1. バイナリをパスの通ったディレクトリにコピー
	cp $(BIN_NAME) /usr/local/bin/
	
	# 2. 設定ファイルを所定のディレクトリにコピー
	cp $(CONF_NAME) $(ETC_DIR)/$(CONF_NAME).sample
	
	# 3. systemdサービス定義ファイルをコピー
	cp $(SERVICE_NAME) $(SYSTEMD_DIR)/$(SERVICE_NAME)
	
	# 4. systemdにサービスを登録し、起動する
	systemctl daemon-reload
	systemctl enable $(SERVICE_NAME)
	systemctl start $(SERVICE_NAME)
	
clean:
	go clean
