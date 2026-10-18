; Windows インストーラーの追加処理。
; 同梱の PHP は VC++ 2015-2022 ランタイム（x64）を必要とする。入っていない PC にだけ、同梱の vc_redist を静かに入れる
; （入れる PC では管理者の確認が 1 回出る）。入っていれば何もしない。
!macro customInstall
  SetRegView 64
  ReadRegDWORD $0 HKLM "SOFTWARE\Microsoft\VisualStudio\14.0\VC\Runtimes\x64" "Installed"
  ${If} $0 != 1
    InitPluginsDir
    DetailPrint "VC++ ランタイムをインストールしています…"
    File "/oname=$PLUGINSDIR\vc_redist.x64.exe" "${BUILD_RESOURCES_DIR}\vc_redist.x64.exe"
    ExecWait '"$PLUGINSDIR\vc_redist.x64.exe" /install /quiet /norestart' $1
    DetailPrint "VC++ ランタイムのインストール結果: $1"
  ${EndIf}
  SetRegView lastused
!macroend
