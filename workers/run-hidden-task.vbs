Option Explicit

Dim shell, executable, arguments, command, exitCode

If WScript.Arguments.Count < 2 Then
    WScript.Quit 2
End If

executable = WScript.Arguments(0)
arguments = WScript.Arguments(1)
command = """" & executable & """"

If arguments <> "" Then
    command = command & " " & arguments
End If

Set shell = CreateObject("WScript.Shell")
exitCode = shell.Run(command, 0, True)
WScript.Quit exitCode
