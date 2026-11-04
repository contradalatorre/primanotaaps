# Avvia o riprende l'orchestratore. Pianificato ogni ora; se una sessione e' gia' in corso non fa nulla.
# Registrazione (una volta):
#   schtasks /Create /SC HOURLY /TN "PrimaNota-Orchestratore" /TR "powershell -NoProfile -ExecutionPolicy Bypass -File \"<percorso>\riprendi.ps1\""
param(
    [string]$Repo     = "C:\Users\andre\Claude\Projects\primanotaaps",
    [string]$Fabbrica = "C:\Users\andre\Claude\Projects\primanotaaps-fabbrica"
)
$ErrorActionPreference = "Stop"

$Stato = Join-Path $Fabbrica ".scratch\orchestrazione"
$Log   = Join-Path $Stato "riprendi.log"
function Scrivi($m) { Add-Content -Path $Log -Value "$(Get-Date -Format s) $m" -Encoding utf8 }

# Worktree dedicato sul branch di integrazione, separato dalla cartella in cui lavori tu.
if (-not (Test-Path $Fabbrica)) {
    git -C $Repo fetch origin
    $esiste = git -C $Repo ls-remote --heads origin integrazione
    if ($esiste) { git -C $Repo worktree add $Fabbrica integrazione }
    else         { git -C $Repo worktree add -b integrazione $Fabbrica origin/main }
}
New-Item -ItemType Directory -Force $Stato | Out-Null

# .claude/ e' escluso da git in questo repo: agenti e regole si copiano a ogni avvio dalla cartella versionata.
New-Item -ItemType Directory -Force (Join-Path $Fabbrica ".claude\agents") | Out-Null
Copy-Item (Join-Path $Fabbrica "orchestrazione\agents\*.md") (Join-Path $Fabbrica ".claude\agents") -Force
Copy-Item (Join-Path $Fabbrica "orchestrazione\settings.json") (Join-Path $Fabbrica ".claude\settings.json") -Force
# Lo stato dell'orchestratore non va mai committato.
$Exclude = Join-Path $Repo ".git\info\exclude"
if (-not (Select-String -Path $Exclude -Pattern '^\.scratch/$' -Quiet)) { Add-Content -Path $Exclude -Value ".scratch/" }

if (Test-Path (Join-Path $Stato "FATTO")) { Scrivi "FATTO presente, niente da fare"; exit 0 }
if (Test-Path (Join-Path $Stato "FERMO")) { Scrivi "FERMO presente: serve un intervento umano"; exit 0 }

# Una sola sessione alla volta.
$Lock = Join-Path $Stato "run.lock"
if (Test-Path $Lock) {
    $vecchio = Get-Content $Lock
    if (Get-Process -Id $vecchio -ErrorAction SilentlyContinue) { Scrivi "sessione $vecchio ancora attiva"; exit 0 }
    Remove-Item $Lock
}
Set-Content -Path $Lock -Value $PID

try {
    $FileId = Join-Path $Stato "session.txt"
    # Non impostare CLAUDE_CODE_EFFORT_LEVEL ne' effortLevel: prevarrebbero sull'effort dei subagent.
    $comuni = @("-p", "--model", "opus", "--effort", "xhigh", "--advisor", "opus",
                "--permission-mode", "auto", "--permission-prompts", "none",
                "--output-format", "stream-json", "--verbose")
    $Jsonl = Join-Path $Stato "sessione.jsonl"
    Set-Location $Fabbrica
    # In PowerShell 5.1 lo stderr di un eseguibile rediretto diventa errore: con Stop interromperebbe lo script.
    $ErrorActionPreference = "Continue"

    if (-not (Test-Path $FileId)) {
        $id = [guid]::NewGuid().ToString()
        Set-Content -Path $FileId -Value $id
        $goal = "/goal Seguendo le istruzioni del system prompt, nell'ultimo turno stampi l'output di due comandi ed entrambi valgono 0: " +
                "``gh api repos/contradalatorre/primanotaaps/milestones?state=open --jq length`` e " +
                "``gh issue list --state open --limit 200 --search=-label:ready-for-human --json number --jq 'map(select(.number!=33))|length'``; " +
                "inoltre esiste il file .scratch/orchestrazione/FATTO. Oppure fermati dopo 300 turni."
        Scrivi "avvio nuova sessione $id"
        & claude @comuni --session-id $id --append-system-prompt-file (Join-Path $Fabbrica "orchestrazione\PROMPT.md") $goal 2>&1 | Out-File -FilePath $Jsonl -Append -Encoding utf8
    } else {
        $id = (Get-Content $FileId).Trim()
        Scrivi "ripresa sessione $id"
        & claude @comuni --resume $id "Riprendi: riconcilia STATO.md con GitHub e continua verso l'obiettivo." 2>&1 | Out-File -FilePath $Jsonl -Append -Encoding utf8
    }
    $codice = $LASTEXITCODE
    Scrivi "claude terminato con codice $codice"

    # Uscita pulita senza FATTO: obiettivo dichiarato impossibile o senza progressi. Dopo 3 volte di fila ci si ferma.
    $Vuote = Join-Path $Stato "uscite-senza-fine.txt"
    if ($codice -eq 0 -and -not (Test-Path (Join-Path $Stato "FATTO"))) {
        $n = 1; if (Test-Path $Vuote) { $n = [int](Get-Content $Vuote) + 1 }
        Set-Content -Path $Vuote -Value $n
        if ($n -ge 3) { Set-Content -Path (Join-Path $Stato "FERMO") -Value "3 uscite senza FATTO, vedi STATO.md"; Scrivi "FERMO" }
    } elseif ($codice -ne 0) {
        # Probabile limite di sessione: si riprova al prossimo giro orario.
        if (Test-Path $Vuote) { Remove-Item $Vuote }
    }
} finally {
    Remove-Item $Lock -ErrorAction SilentlyContinue
}
