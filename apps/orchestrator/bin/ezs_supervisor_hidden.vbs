Option Explicit
' WScript host hides the child PowerShell console and waits for its exit code.
Dim WshShell, command, result
Set WshShell = CreateObject("WScript.Shell")
command = "powershell.exe -NoProfile -NonInteractive -ExecutionPolicy Bypass -File " & Chr(34) & "H:\EZS_orchestrator\scripts\ezs_supervisor_tick.ps1" & Chr(34)
result = WshShell.Run(command, 0, True)
WScript.Quit result
