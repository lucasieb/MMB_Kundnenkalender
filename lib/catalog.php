<?php
declare(strict_types=1);
function mmb_catalog(): array {
    return [
        'version'=>'2026-10-21-v1',
        'pricing'=>require __DIR__.'/pricing_config.php',
        'packages'=>[
            'single'=>['name'=>'Einzelbuchung','icon'=>'speaker','description'=>'Musik, Fotobox, Zelt oder Mischpult – frei kombinieren.','from'=>29],
            'dj'=>['name'=>'DJ-Set','icon'=>'music','description'=>'2× SOUNDBOKS 4 und 2 Stative. Mischpult optional.','from'=>61.20],
            'party'=>['name'=>'Party-Set','icon'=>'camera','description'=>'DJ-Set plus Fotobox Digital mit Stativ und Requisiten.','from'=>180.20],
            'event'=>['name'=>'Event-Set','icon'=>'tent','description'=>'Party-Set plus Zelt 4 × 8 m zum Selbstaufbau.','from'=>259.20],
        ],
        'photo'=>[
            'digital'=>['name'=>'Digital','price'=>139,'description'=>'Fotobox, Stativ, Requisiten · unbegrenzt Fotos · Download über die Fotocloud für 60 Tage.'],
            'print200'=>['name'=>'Print 200','price'=>219,'description'=>'Alles aus Digital + Drucker, 200 Ausdrucke und 2 individuelle Layouts.'],
            'print700'=>['name'=>'Print 700','price'=>295,'description'=>'Alles aus Digital + Drucker, 700 Ausdrucke und 3 individuelle Layouts.'],
        ],
        // Zubehör-Grundpreis gilt für bis zu 3 Kalendertage, danach je zusätzlichem Tag.
        'extras'=>[
            'stands'=>['name'=>'Stativ','icon'=>'sliders-horizontal','price'=>9,'extra_day'=>2,'max'=>2],
            'microphone'=>['name'=>'Mikrofon','icon'=>'mic','price'=>9,'extra_day'=>2,'max'=>1],
            'batteries'=>['name'=>'Zusätzlicher Akku','icon'=>'battery','price'=>9,'extra_day'=>2,'max'=>3],
            'backpack'=>['name'=>'SOUNDBOKS-Rucksack','icon'=>'backpack','price'=>5,'extra_day'=>1,'max'=>1],
            'controller'=>['name'=>'Pioneer DDJ-FLX4 Mischpult','icon'=>'sliders-horizontal','price'=>29,'with_music'=>19,'extra_day'=>5,'max'=>1],
        ],
        'tent'=>['name'=>'Zelt 4 × 8 m','first_day'=>99,'extra_day'=>25,'walls'=>20],
        'photo_included_days'=>5,'photo_extra_day'=>40,
        'set_music_discount_percent'=>10,'set_photo_discount'=>20,'event_tent_discount'=>20,
        'shipping_per_speaker'=>80,
        'assembly_setup'=>80,'assembly_teardown'=>80,
        'delivery_note'=>'Lieferkosten nach Entfernung auf Angebot. Abholung in Wesel kostenlos.',
        'assembly_note'=>'Auf- und Abbau des Zelts: 160 € (jeweils zwei Personen und rund eine Stunde kalkuliert), bereits enthalten. Lieferfahrt nach Entfernung zusätzlich auf Angebot.',
    ];
}
