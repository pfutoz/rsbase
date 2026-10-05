' --- watcher.vbs (Multi-NIC / VPN Auto-Detection + JPEG Conversion Support) ---
Option Explicit

Dim objShell, objFSO, network, tmpDir, normalExePath, ecgExePath
Dim desktopPath, toolDir, scriptPath
Dim myIPs, loopCount, currentIp, myTriggerFile
Dim objFile, fileContent, delimiterPos, mode, payload
Dim cleanPath, launchCmd, subFolder

Set objShell = CreateObject("WScript.Shell")
Set objFSO = CreateObject("Scripting.FileSystemObject")
Set network = CreateObject("WScript.Network")

' Configuration Paths
tmpDir = "\\192.168.1.16\htdocs\rsbase\tmp\"
normalExePath = "C:\RSBASE\RSB_public_html\RSViewer\rsview.exe"
ecgExePath    = "C:\RSBASE\RSB_public_html\denshi\FEV-05.exe"

' DicomTool Path (Desktop\DicomTool with fallback to C:\Users\Owner\Desktop\DicomTool)
desktopPath = objShell.SpecialFolders("Desktop")
toolDir = desktopPath & "\DicomTool\"
If Not objFSO.FolderExists(toolDir) Then
    toolDir = "C:\Users\Owner\Desktop\DicomTool\"
End If
scriptPath = toolDir & "dicom_convert.py"

' Function to retrieve all active IPv4 addresses (Ethernet, Wi-Fi, VPN)
Function GetAllMyIPs()
    Dim ipList, ip, colItems, objItem, i
    Set ipList = CreateObject("Scripting.Dictionary")
    On Error Resume Next

    ' 1. StandardCimv2 (Windows 10/11: includes VPN, TAP, Wi-Fi, Ethernet)
    Set colItems = GetObject("winmgmts:\\.\root\StandardCimv2").ExecQuery("Select IPAddress from MSFT_NetIPAddress Where AddressFamily = 2")
    If Err.Number = 0 Then
        For Each objItem in colItems
            ip = objItem.IPAddress
            If InStr(ip, ".") > 0 And Left(ip, 7) <> "169.254" And ip <> "127.0.0.1" Then
                If Not ipList.Exists(ip) Then ipList.Add ip, True
            End If
        Next
    End If
    Err.Clear

    ' 2. Win32_NetworkAdapterConfiguration (Fallback for older Windows)
    Set colItems = GetObject("winmgmts:\\.\root\cimv2").ExecQuery("Select IPAddress from Win32_NetworkAdapterConfiguration Where IPEnabled = True")
    If Err.Number = 0 Then
        For Each objItem in colItems
            If Not IsNull(objItem.IPAddress) Then
                For i = 0 To UBound(objItem.IPAddress)
                    ip = objItem.IPAddress(i)
                    If InStr(ip, ".") > 0 And Left(ip, 7) <> "169.254" And ip <> "127.0.0.1" Then
                        If Not ipList.Exists(ip) Then ipList.Add ip, True
                    End If
                Next
            End If
        Next
    End If
    On Error Goto 0

    GetAllMyIPs = ipList.Keys
End Function

myIPs = GetAllMyIPs()
loopCount = 0

' Main Polling Loop (checks every 1 second)
Do While True
    On Error Resume Next

    ' Refresh IP list every 10 seconds (auto-detects VPN connect/disconnect)
    If loopCount Mod 10 = 0 Then
        myIPs = GetAllMyIPs()
    End If
    loopCount = loopCount + 1

    ' Check trigger file for all detected local IPs
    For Each currentIp in myIPs
        myTriggerFile = tmpDir & "trigger_" & currentIp & ".txt"

        If objFSO.FileExists(myTriggerFile) Then
            fileContent = ""

            ' Read trigger file
            Set objFile = objFSO.OpenTextFile(myTriggerFile, 1, False)
            If Not objFile.AtEndOfStream Then
                fileContent = Trim(objFile.ReadAll)
            End If
            objFile.Close
            Set objFile = Nothing

            ' Delete trigger file immediately to prevent duplicate launch
            objFSO.DeleteFile myTriggerFile, True

            If Len(fileContent) > 0 Then
                delimiterPos = InStr(fileContent, "|")
                
                If delimiterPos > 0 Then
                    mode = Left(fileContent, delimiterPos - 1)
                    payload = Mid(fileContent, delimiterPos + 1)
                Else
                    mode = "NORMAL"
                    payload = fileContent
                End If

                cleanPath = Replace(payload, "/", "\")

                If mode = "ECG" Then
                    ' Launch ECG Viewer (FEV-05.exe)
                    If objFSO.FileExists(ecgExePath) Then
                        launchCmd = """" & ecgExePath & """ """ & cleanPath & """"
                        objShell.Run launchCmd, 1, False
                    End If
                ElseIf mode = "JPEG" Then
                    ' 胃がん検診 DICOM→JPEG 変換モード
                    If Not objFSO.FileExists(scriptPath) Then
                        MsgBox "DICOM変換ツールが見つかりません。" & vbCrLf & scriptPath, 16, "ツール不在エラー"
                    Else
                        subFolder = InputBox("保存するサブフォルダ名を入力してください:", "フォルダ名指定")
                        If Trim(subFolder) <> "" Then
                            ' パスの末尾に '\' があるとクォーテーションを巻き込むため、安全にトリム処理
                            If Right(cleanPath, 1) = "\" Then
                                cleanPath = Left(cleanPath, Len(cleanPath) - 1)
                            End If
                            
                            ' 独立した引数として確実に渡す
                            launchCmd = "cmd.exe /k python """ & scriptPath & """ """ & cleanPath & """ """ & subFolder & """"
                            objShell.Run launchCmd, 1, False
                        End If
                    End If
                Else
                    ' Launch DICOM Viewer (rsview.exe) with multi-instance support
                    If objFSO.FileExists(normalExePath) Then
                        launchCmd = "cmd.exe /c start """" """ & normalExePath & """ """ & cleanPath & """"
                        objShell.Run launchCmd, 7, False
                    End If
                End If
            End If

            Exit For
        End If
    Next
    On Error Goto 0

    ' Wait 1000ms
    WScript.Sleep 1000
Loop