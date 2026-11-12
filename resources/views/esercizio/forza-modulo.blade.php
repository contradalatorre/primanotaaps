{{-- I campi e i pulsanti per forzare la natura commerciale delle imputazioni spuntate (#52); va dentro un form (con @csrf) che le elenca come `imputazioni[]`. --}}
@can('importare_e_confermare')
    <fieldset data-merlo="forzatura-modulo">
        <legend>Forza la natura commerciale delle imputazioni spuntate</legend>
        <p class="nota">Una forzatura sostituisce la natura calcolata, che resta visibile accanto, e finisce nel registro delle modifiche con la tua motivazione. Vale solo per le entrate.</p>
        <label class="spunta"><input type="radio" name="natura" value="commerciale" @checked(old('natura') === 'commerciale')> <span>Commerciale</span></label>
        <label class="spunta"><input type="radio" name="natura" value="non_commerciale" @checked(old('natura') === 'non_commerciale')> <span>Non commerciale</span></label>
        <label>Motivazione <textarea name="motivazione" rows="2" maxlength="{{ \App\Forzature::MOTIVAZIONE_MASSIMO }}">{{ old('motivazione') }}</textarea></label>
        <button type="submit" formaction="{{ route('natura.forza') }}" class="bottone">Forza la natura</button>
        <button type="submit" formaction="{{ route('natura.togli') }}" class="link">Togli la forzatura</button>
    </fieldset>
@endcan
