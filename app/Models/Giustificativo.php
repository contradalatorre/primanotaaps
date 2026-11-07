<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;

/** Il documento che prova un movimento: un riferimento testuale e, se c'è, il link al file sul Drive. */
#[Fillable(['riferimento', 'link'])]
class Giustificativo extends Model
{
    protected $table = 'giustificativi';
}
