; Windows インストーラーの追加処理。
; 同梱の PHP は VC++ 2015-2022 ランタイム（x64）を必要とする。入っていない PC にだけ、同梱の vc_redist を静かに入れる
; （入れる PC では管理者の確認が 1 回出る）。入っていれば何もしない。
; 表示文言はインストーラーの言語（Windows の表示言語）に合わせる。
; この .nsh は MUI_LANGUAGE より前に取り込まれ ${LANG_ENGLISH} 等がまだ無いため、messages.nsh と同じく LCID の数値（1033=英語、1041=日本語）で宣言する
LangString KP_VCREDIST_INSTALLING 1033 "Installing the VC++ runtime..."
LangString KP_VCREDIST_INSTALLING 1041 "VC++ ランタイムをインストールしています…"
LangString KP_VCREDIST_RESULT 1033 "VC++ runtime installation result:"
LangString KP_VCREDIST_RESULT 1041 "VC++ ランタイムのインストール結果:"

!macro customInstall
  SetRegView 64
  ReadRegDWORD $0 HKLM "SOFTWARE\Microsoft\VisualStudio\14.0\VC\Runtimes\x64" "Installed"
  ${If} $0 != 1
    InitPluginsDir
    DetailPrint "$(KP_VCREDIST_INSTALLING)"
    File "/oname=$PLUGINSDIR\vc_redist.x64.exe" "${BUILD_RESOURCES_DIR}\vc_redist.x64.exe"
    ExecWait '"$PLUGINSDIR\vc_redist.x64.exe" /install /quiet /norestart' $1
    DetailPrint "$(KP_VCREDIST_RESULT) $1"
  ${EndIf}
  SetRegView lastused
!macroend
