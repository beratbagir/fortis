<?php

return [

    /*
    | Home'daki örnek sorular, departmana göre (yoksa default). type: question | agent | report | presentation.
    | requires: cevap için gereken kaynaklar; bağlı değilse öneri gösterilmez. Veri soruları (type=question)
    | AI veri erişimi kapalıyken gösterilmez. Ekranda en fazla 4 öneri görünür.
    */
    'suggestions' => [
        'default' => [
            ['type' => 'question', 'text' => 'Dün en çok rötar yapan 5 uçuş hangileri?', 'requires' => ['apm']],
            ['type' => 'question', 'text' => 'Bu hafta AYT çıkışlı uçuşlarda zamanında kalkış oranı nasıl?', 'requires' => ['apm']],
            ['type' => 'question', 'text' => 'Dün yük faktörü en düşük rotalar hangileriydi?', 'requires' => ['ibs']],
            ['type' => 'agent', 'text' => 'Her sabah dünkü rötarları özetleyen bir agent kur', 'requires' => ['apm']],
            ['type' => 'report', 'text' => 'Dünkü iptal edilen uçuşları Excel olarak ver', 'requires' => ['apm']],
            ['type' => 'agent', 'text' => 'Bir sitenin her sabah çalışıp çalışmadığını kontrol eden bir otomasyon kur', 'requires' => []],
            ['type' => 'presentation', 'text' => 'Bu haftanın operasyon özetini tek sayfalık bir panel olarak hazırla', 'requires' => []],
        ],
        'Finance' => [
            ['type' => 'question', 'text' => 'Dün kaç bilet sattık, toplam tutar ne kadar?', 'requires' => ['ibs']],
            ['type' => 'question', 'text' => 'Geçen hafta satışların kanallara göre dağılımı nasıldı?', 'requires' => ['ibs']],
            ['type' => 'report', 'text' => 'Dünkü satışları kanala göre Excel olarak ver', 'requires' => ['ibs']],
            ['type' => 'agent', 'text' => 'Her pazartesi geçen haftanın satış özetini gönderen bir agent kur', 'requires' => ['ibs']],
        ],
        'Commercial' => [
            ['type' => 'question', 'text' => 'Bu hafta AYT çıkışlı uçuşlarda ortalama doluluk nasıl, geçen haftayla karşılaştır.', 'requires' => ['ibs']],
            ['type' => 'question', 'text' => 'Dün yük faktörü en düşük rotalar hangileriydi?', 'requires' => ['ibs']],
            ['type' => 'report', 'text' => 'Gelecek haftanın doluluk tablosunu Excel olarak ver', 'requires' => ['ibs']],
            ['type' => 'agent', 'text' => 'Doluluğu %60\'ın altındaki seferleri her sabah bildiren bir agent kur', 'requires' => ['ibs']],
        ],
        'Flight Ops' => [
            ['type' => 'question', 'text' => 'Dün en çok rötar yapan 5 uçuş hangileri?', 'requires' => ['apm']],
            ['type' => 'question', 'text' => 'Dünkü rötarlarda en sık görülen rötar kodları neler?', 'requires' => ['apm']],
            ['type' => 'agent', 'text' => 'Rötar 4 saati geçerse ops ekibine Teams\'ten haber veren bir otomasyon kur', 'requires' => ['apm']],
            ['type' => 'report', 'text' => 'Dünkü uçuşların listesini Excel olarak ver', 'requires' => ['apm']],
        ],
        'Customer Relations' => [
            ['type' => 'question', 'text' => 'Dün iptal edilen uçuş var mı, varsa hangileri?', 'requires' => ['apm']],
            ['type' => 'question', 'text' => 'Dün 3 saatten uzun rötar yapan uçuşlar hangileri?', 'requires' => ['apm']],
            ['type' => 'report', 'text' => 'Dünkü iptal edilen uçuşları Excel olarak ver', 'requires' => ['apm']],
            ['type' => 'agent', 'text' => 'Uzun rötarlı seferleri her sabah listeleyen bir agent kur', 'requires' => ['apm']],
        ],
    ],

];
