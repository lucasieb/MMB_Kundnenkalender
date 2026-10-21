<?php
declare(strict_types=1);
function mmb_catalog(): array {
    return [
        'version'=>'2026-10-22-v3',
        'pricing'=>require __DIR__.'/pricing_config.php',
        'packages'=>[
            'single'=>['name'=>'Einzelbuchung','icon'=>'speaker','description'=>'Musik, Fotobox, Eventzelt oder Mischpult – frei kombinieren.'],
            'dj'=>['name'=>'DJ-Set','icon'=>'music','description'=>'2× SOUNDBOKS 4 und 2 Stative. Mischpult optional.'],
            'party'=>['name'=>'Party-Set','icon'=>'camera','description'=>'DJ-Set plus Fotobox Digital mit Stativ und Requisiten.'],
            'event'=>['name'=>'Event-Set','icon'=>'tent','description'=>'Party-Set plus Eventzelt 4 × 8 m zum Selbstaufbau.'],
        ],
        'photo'=>[
            'digital'=>['name'=>'Digital','price'=>139,'description'=>'Fotobox, Stativ, Requisiten · unbegrenzt Fotos · Download über die Fotocloud für 60 Tage.'],
            'print200'=>['name'=>'Print 200','price'=>219,'description'=>'Alles aus Digital + Drucker, 200 Ausdrucke und 2 individuelle Layouts.'],
            'print700'=>['name'=>'Print 700','price'=>295,'description'=>'Alles aus Digital + Drucker, 700 Ausdrucke und 3 individuelle Layouts.'],
        ],
        // Tagespreise; derselbe Mietdauerrabatt wie bei Musikboxen.
        'extras'=>[
            'stands'=>['name'=>'Stativ','icon'=>'sliders-horizontal','price'=>5,'max'=>2],
            'microphone'=>['name'=>'Mikrofon','icon'=>'mic','price'=>9,'max'=>2],
            'batteries'=>['name'=>'Zusätzlicher Akku','icon'=>'battery','price'=>15,'max'=>3],
            'backpack'=>['name'=>'SOUNDBOKS-Rucksack','icon'=>'backpack','price'=>9,'max'=>1],
            'controller'=>['name'=>'Pioneer DDJ-FLX4 Mischpult','icon'=>'sliders-horizontal','price'=>19,'max'=>1],
        ],
        'tent'=>['name'=>'Eventzelt 4 × 8 m','weekday'=>99,'weekend'=>129,'walls'=>20],
        'photo_included_days'=>5,'photo_extra_day'=>40,
        'set_discounts'=>['single'=>0,'dj'=>10,'party'=>15,'event'=>20],
        'shipping_per_speaker'=>80,
        'assembly_setup'=>80,'assembly_teardown'=>80,
        'delivery_note'=>'Lieferkosten nach Entfernung auf Angebot. Abholung in Wesel kostenlos.',
        'assembly_note'=>'Auf- und Abbau des Eventzelts: 160 € (jeweils zwei Personen und rund eine Stunde kalkuliert), bereits enthalten. Lieferfahrt nach Entfernung zusätzlich auf Angebot.',
    ];
}
