Option Explicit
Dim WshShell, command, result
Set WshShell = CreateObject("WScript.Shell")
command = "powershell.exe -NoProfile -NonInteractive -ExecutionPolicy Bypass -File " & Chr(34) & "H:\EZStudio_plateforme\apps\orchestrator\scripts\ezs_supervisor_resident.ps1" & Chr(34)
result = WshShell.Run(command, 0, True)
WScript.Quit result
