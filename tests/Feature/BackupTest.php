<?php

namespace Tests\Feature;

use App\Backup\Esportazione;
use App\Mail\ErroreGrave;
use App\Models\Backup;
use App\Models\Conto;
use App\Models\Errore;
use App\Models\RegistroAccesso;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;
use Tests\ConDriveFinto;
use Tests\ConSpazioDiProva;
use Tests\TestCase;
use ZipArchive;

/** Il backup (#68): esportazione completa sul drive condiviso, rotazione, errori. Drive e posta simulati, dati inventati. */
class BackupTest extends TestCase
{
    use ConDriveFinto, ConSpazioDiProva, RefreshDatabase;

    private const SEGNALE = 'SEGNALE-CHE-NON-DEVE-USCIRE';

    private User $amministratore;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo('2026-06-10 10:00');
        Mail::fake();
        $this->configuraDrive();
        $this->fingiDrive();
        $this->amministratore = $this->utenteCon('Amministratore', [], ['name' => 'Anna Bianchi']);
        Conto::factory()->create(['nome' => 'Conto corrente inventato']);
    }

    private function eseguiSuRichiesta()
    {
        return $this->actingAs($this->amministratore)->post(route('sistema.backup'));
    }

    /** Lo zip che l'app ha mandato al drive, aperto. */
    private function zipCaricato(): ZipArchive
    {
        $richiesta = Http::recorded(fn (Request $r) => str_contains($r->url(), '/upload/drive/'))->first()[0];
        $corpo = $richiesta->body();
        $inizio = strpos($corpo, "Content-Type: application/zip\r\n\r\n") + strlen("Content-Type: application/zip\r\n\r\n");
        $fine = strrpos($corpo, "\r\n--");
        $percorso = tempnam(sys_get_temp_dir(), 'zip');
        file_put_contents($percorso, substr($corpo, $inizio, $fine - $inizio));
        $zip = new ZipArchive;
        $this->assertTrue($zip->open($percorso));
        unlink($percorso);

        return $zip;
    }

    public function test_il_backup_su_richiesta_manda_al_drive_database_e_csv_leggibili(): void
    {
        $this->eseguiSuRichiesta()->assertRedirect(route('sistema'))->assertSessionHas('ok');

        $backup = Backup::sole();
        $this->assertSame('su_richiesta', $backup->tipo);
        $this->assertSame('riuscito', $backup->esito);
        $this->assertSame($this->amministratore->id, $backup->user_id);
        $this->assertSame('file-1', $backup->drive_id);
        $this->assertSame('prima-nota-backup-2026-06-10-100000.zip', $backup->file);
        Http::assertSent(fn (Request $r) => str_contains($r->body(), '"name":"prima-nota-backup-2026-06-10-100000.zip"') && str_contains($r->body(), '"parents":["cartella-123"]'));

        $zip = $this->zipCaricato();
        $sql = $zip->getFromName('database.sql');
        $this->assertStringContainsString('CREATE TABLE `conti`', $sql);
        $this->assertStringContainsString("'Conto corrente inventato'", $sql);
        $csv = $zip->getFromName('csv/conti.csv');
        $this->assertStringStartsWith("\xEF\xBB\xBF", $csv); // l'Excel italiano lo apre in UTF-8
        $this->assertStringContainsString('nome', explode("\n", $csv)[0]);
        $this->assertStringContainsString('Conto corrente inventato', $csv);
        $this->assertNotFalse($zip->getFromName('LEGGIMI.txt'));
    }

    public function test_l_esportazione_e_registrata_nel_registro_degli_accessi(): void
    {
        $this->eseguiSuRichiesta();

        $riga = RegistroAccesso::where('evento', 'esportazione_backup')->sole();
        $this->assertSame($this->amministratore->id, $riga->user_id);
    }

    public function test_un_backup_non_riuscito_non_e_un_esportazione_nel_registro_degli_accessi(): void
    {
        config(['services.drive.credenziali' => null]);

        $this->eseguiSuRichiesta();

        $this->assertSame(0, RegistroAccesso::where('evento', 'esportazione_backup')->count());
    }

    public function test_nei_csv_le_formule_si_neutralizzano_ma_i_numeri_negativi_restano(): void
    {
        Conto::factory()->create(['nome' => '=HYPERLINK("http://x.example")', 'saldo_iniziale' => -500]);

        $this->eseguiSuRichiesta();

        $csv = $this->zipCaricato()->getFromName('csv/conti.csv');
        $this->assertStringContainsString("'=HYPERLINK", $csv);
        $this->assertStringContainsString(';-500;', $csv);
    }

    public function test_il_backup_non_porta_segreti_ne_tabelle_di_servizio_ne_spazi_di_prova(): void
    {
        User::factory()->create(['name' => 'Persona inventata', 'google_refresh_token' => 'REFRESH-TOKEN-'.self::SEGNALE]);
        DB::table('sessions')->insert(['id' => 'sessione-1', 'payload' => 'SESSIONE-'.self::SEGNALE, 'last_activity' => time()]);

        $this->eseguiSuRichiesta();
        $zip = $this->zipCaricato();

        $tutto = '';
        for ($i = 0; $i < $zip->numFiles; $i++) {
            $tutto .= $zip->getFromIndex($i);
        }
        $this->assertStringContainsString('Persona inventata', $tutto); // i dati veri ci sono
        $this->assertStringNotContainsString(self::SEGNALE, $tutto);
        $this->assertFalse($zip->getFromName('csv/sessions.csv'));
    }

    public function test_le_tabelle_di_servizio_e_quelle_dello_spazio_di_prova_non_si_esportano(): void
    {
        foreach (['conti', 'movimenti', 'users', 'registro_modifiche', 'migrations', 'onboarding_avanzamenti'] as $tabella) {
            $this->assertTrue(Esportazione::tabellaDaEsportare($tabella), $tabella);
        }
        // Lo spazio di prova sta in un database a parte (ADR 0003); se per errore fosse nello stesso, le sue `p<id>_…` restano fuori.
        foreach (['sessions', 'cache', 'cache_locks', 'jobs', 'job_batches', 'failed_jobs', 'password_reset_tokens', 'p1_conti', 'p12_users'] as $tabella) {
            $this->assertFalse(Esportazione::tabellaDaEsportare($tabella), $tabella);
        }
    }

    public function test_non_restano_file_temporanei_nello_spazio_dell_app(): void
    {
        $this->eseguiSuRichiesta();

        $this->assertSame([], Storage::disk('local')->allFiles('backup-tmp'));
        $this->assertSame([], glob(storage_path('app/public/*')) ?: []);
    }

    public function test_dopo_il_caricamento_la_rotazione_toglie_dal_drive_il_piu_vecchio(): void
    {
        foreach (range(1, 12) as $mese) { // 12 mensili riusciti, da giugno 2025 a maggio 2026
            $quando = now()->startOfMonth()->subMonths(12 - $mese + 1);
            Backup::create(['tipo' => 'mensile', 'esito' => 'riuscito', 'avviato_il' => $quando, 'concluso_il' => $quando, 'drive_id' => "vecchio-$mese", 'file' => "v$mese.zip"]);
        }

        $this->eseguiSuRichiesta();

        // con quello di oggi sono 13 mesi diversi (giu 2025 .. giu 2026): esce il più vecchio, che non è l'ultimo del suo anno
        $this->assertNotNull(Backup::where('drive_id', 'vecchio-1')->value('eliminato_il'));
        $this->assertNull(Backup::where('drive_id', 'vecchio-2')->value('eliminato_il'));
        Http::assertSent(fn (Request $r) => $r->method() === 'DELETE' && str_contains($r->url(), '/files/vecchio-1?'));
        Http::assertNotSent(fn (Request $r) => $r->method() === 'DELETE' && str_contains($r->url(), '/files/vecchio-2?'));
    }

    public function test_un_backup_su_richiesta_non_toglie_il_mensile_dello_stesso_mese(): void
    {
        Backup::create(['tipo' => 'mensile', 'esito' => 'riuscito', 'avviato_il' => '2026-06-01 03:00', 'concluso_il' => '2026-06-01 03:00', 'drive_id' => 'mensile-giugno', 'file' => 'm.zip']);

        $this->eseguiSuRichiesta();

        $this->assertSame(2, Backup::sulDrive()->count());
        Http::assertNotSent(fn (Request $r) => $r->method() === 'DELETE');
    }

    public function test_se_il_drive_non_risponde_il_backup_fallisce_registrato_e_con_email_agli_amministratori(): void
    {
        $this->fingiDrive(['www.googleapis.com/upload/drive/v3/files*' => Http::response(['error' => ['message' => self::SEGNALE]], 500)]);
        $altro = $this->utenteCon('Amministratore', [], ['name' => 'Bruno Neri']);
        $this->utenteCon('Tesoriere');

        $this->eseguiSuRichiesta()->assertRedirect(route('sistema'))->assertSessionHas('avviso');

        $backup = Backup::sole();
        $this->assertSame('fallito', $backup->esito);
        $this->assertStringContainsString('500', $backup->errore);
        $this->assertNull($backup->drive_id);
        $errore = Errore::sole();
        $this->assertTrue($errore->grave);
        $this->assertSame('backup', $errore->origine);
        $this->assertNull($errore->risolto_il);
        Mail::assertSent(ErroreGrave::class, 1);
        Mail::assertSent(ErroreGrave::class, fn ($m) => $m->hasTo($this->amministratore->email) && $m->hasTo($altro->email) && count($m->to) === 2 && $m->origine === 'backup');
        // niente di quello che ha detto Google arriva ai dati, alla posta o alla pagina
        $this->assertStringNotContainsString(self::SEGNALE, json_encode([Backup::all(), Errore::all()]));
        $this->assertStringNotContainsString(self::SEGNALE, Mail::sent(ErroreGrave::class)->first()->render());
        $this->assertSame([], Storage::disk('local')->allFiles('backup-tmp'));
    }

    public function test_senza_credenziali_il_backup_fallisce_senza_chiamare_nessuno(): void
    {
        config(['services.drive.credenziali' => null]);

        $this->eseguiSuRichiesta()->assertSessionHas('avviso');

        $this->assertSame('fallito', Backup::sole()->esito);
        $this->assertStringContainsString('non è configurato', Backup::sole()->errore);
        Http::assertNothingSent();
        Mail::assertSent(ErroreGrave::class, 1);
    }

    public function test_senza_cartella_il_backup_fallisce(): void
    {
        config(['services.drive.cartella_backup' => '']);

        $this->eseguiSuRichiesta()->assertSessionHas('avviso');

        $this->assertStringContainsString('non è configurato', Backup::sole()->errore);
        Http::assertNothingSent();
    }

    public function test_se_la_posta_non_funziona_il_backup_fallito_resta_registrato(): void
    {
        config(['services.drive.credenziali' => null]);
        Mail::shouldReceive('to')->andThrow(new \RuntimeException('Posta non raggiungibile'));

        $this->eseguiSuRichiesta()->assertRedirect(route('sistema'));

        $this->assertSame('fallito', Backup::sole()->esito);
        $this->assertSame(['backup', 'posta'], Errore::orderBy('id')->pluck('origine')->all()); // e il guasto della posta si vede
    }

    public function test_un_backup_gia_in_corso_non_se_ne_fa_un_altro(): void
    {
        $lucchetto = Cache::lock('backup', 60);
        $this->assertTrue($lucchetto->get());

        $this->eseguiSuRichiesta()->assertSessionHas('avviso');

        $this->assertSame(0, Backup::count());
        Http::assertNothingSent();
        $lucchetto->release();
    }

    public function test_chi_non_ha_il_permesso_non_lo_avvia(): void
    {
        $this->actingAs($this->utenteCon('Tesoriere'))->post(route('sistema.backup'))->assertForbidden();

        $this->assertSame(0, Backup::count());
        Http::assertNothingSent();
    }

    public function test_durante_una_missione_non_si_avvia(): void
    {
        // In prova valgono i permessi della missione: l'Amministratore non ha `gestire_impostazioni`, e comunque nessun dato di prova entra in un backup (ADR 0003).
        $this->actingAs($this->amministratore)->post(route('impara.avvia', 'benvenuto'));

        $this->post(route('sistema.backup'))->assertForbidden();

        $this->assertSame(0, Backup::count());
        Http::assertNothingSent();
    }

    public function test_il_cron_fa_il_backup_mensile_il_primo_del_mese(): void
    {
        config(['app.token_cron' => 'token-di-prova-lunghissimo']);
        $url = '/manutenzione/cron?token=token-di-prova-lunghissimo';
        $this->travelTo('2026-07-01 02:55');
        $this->get($url)->assertJsonPath('avviati', []);
        $this->travelTo('2026-07-01 03:07');

        $this->get($url)->assertOk()->assertJsonPath('avviati', ['backup:esegui']);

        $backup = Backup::sole();
        $this->assertSame('mensile', $backup->tipo);
        $this->assertSame('riuscito', $backup->esito);
        $this->assertNull($backup->user_id);
        $this->travelTo('2026-07-01 03:17');
        $this->get($url)->assertJsonPath('avviati', []);
        $this->assertSame(1, Backup::count());
    }

    public function test_se_il_backup_mensile_fallisce_il_cron_risponde_bene_e_l_amministratore_riceve_l_email(): void
    {
        $this->fingiDrive(['www.googleapis.com/upload/drive/v3/files*' => Http::response('', 503)]);
        config(['app.token_cron' => 'token-di-prova-lunghissimo']);
        $url = '/manutenzione/cron?token=token-di-prova-lunghissimo';
        $this->travelTo('2026-07-01 02:55');
        $this->get($url);
        $this->travelTo('2026-07-01 03:07');

        $this->get($url)->assertOk();

        $this->assertSame('fallito', Backup::sole()->esito);
        Mail::assertSent(ErroreGrave::class, fn ($m) => $m->hasTo($this->amministratore->email));
    }
}
