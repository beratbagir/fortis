<?php

return [

    /*
    | Claude'un veri sorgulama araçları (query_warehouse, query_apm, query_successfactors).
    | Açma/kapama: settings → ai.data_access (off | masked), admin ayarlar ekranından. Varsayılan off.
    | Bütün sonuçlar Claude'a gitmeden önce App\Ai\AiDataGate'ten geçer.
    */

    // Bir araç çağrısında Claude'a dönen en fazla satır ve karakter. Fazlası kesilir; toplam satır sayısı yine söylenir.
    'max_rows' => (int) env('AI_QUERY_MAX_ROWS', 200),

    'max_chars' => (int) env('AI_QUERY_MAX_CHARS', 40000),

    // Tek bir metin değerinin en fazla uzunluğu (serbest metin alanları).
    'max_value_chars' => 500,

    // Bir cevap (kullanıcı mesajı) içinde en fazla veri sorgusu.
    'max_queries_per_answer' => 8,

    // query_warehouse: LIMIT üst sınırı ve süre sınırı.
    'warehouse_max_limit' => 1000,

    'warehouse_timeout_ms' => 30000,

    /*
    | Alan adına göre maskelenen sütunlar. Sütun adı küçük harfe ve snake_case'e çevrilip bu kalıplarla karşılaştırılır
    | (pax_first_name, contactEmail gibi türevler de yakalanır). Sayım/toplam sütunları (pnr_count, total_pax) hariç.
    */
    'masked_columns' => [
        'first_name', 'last_name', 'given_name', 'family_name', 'middle_name', 'surname', 'full_name', 'pax_name', 'passenger_name', 'name_on_card',
        'email', 'e_mail', 'phone', 'mobile', 'gsm', 'fax', 'telephone',
        'pnr', 'record_locator', 'booking_reference', 'ticket_number', 'ticket_no', 'eticket', 'e_ticket',
        'passport', 'birth_date', 'date_of_birth', 'dob', 'national_id', 'tckn', 'tc_kimlik', 'identity_number',
        'address', 'street', 'zip_code', 'postal_code', 'iban', 'card_number', 'creditcard_number', 'salary',
        // Serbest metin alanları: içinde ad geçebilir ve ad içerikten tanınamaz.
        'remark', 'remarks', 'comment', 'comments', 'memo', 'free_text',
    ],

    /*
    | Operasyonel kod alanları: sefer no, leg id, çağrı adı, kuyruk tescili. Bu sütunlarda kod biçimli değerler (PNR ve
    | pasaport kuralları) maskelenmez; ör. FH489P sonu harfli bir sefer kodudur, PNR değil. E-posta, telefon, TC kimlik ve
    | bilet no kuralları burada da uygulanır. Alan adı maskelenen sütunlara da uyuyorsa maskeleme önceliklidir.
    */
    'operational_columns' => [
        'flight_no', 'flight_number', 'flight_num', 'flt_no', 'designator', 'leg_id', 'legid', 'leg_no',
        'call_sign', 'callsign', 'aircraft_reg', 'aircraft_registration', 'registration', 'ac_reg', 'tail', 'tail_no', 'tail_number',
    ],

    // Bağlantılar ekranında gösterilen maskelenen alan grupları (sadece grup adları; masked_columns'un özetidir).
    'masked_groups' => [
        'Ad ve soyad' => ['first_name', 'last_name', 'full_name', 'pax_name', 'passenger_name'],
        'İletişim bilgileri' => ['email', 'phone', 'mobile', 'fax'],
        'Rezervasyon ve bilet kimlikleri' => ['pnr', 'record_locator', 'booking_reference', 'ticket_number', 'eticket'],
        'Kimlik belgeleri ve doğum tarihi' => ['passport', 'national_id', 'tckn', 'birth_date', 'date_of_birth'],
        'Adres, ödeme ve maaş' => ['address', 'zip_code', 'iban', 'card_number', 'salary'],
        'Serbest metin (not, açıklama)' => ['remark', 'comment', 'memo', 'free_text'],
    ],

    // Bu kelimeleri içeren sütunlar sayım/toplamdır, maskelenmez (ör. pnr_count, total_pax).
    'aggregate_column_words' => ['count', 'cnt', 'total', 'sum', 'avg', 'qty', 'number_of', 'ratio', 'pct', 'rate'],

    // SuccessFactors'tan gelen çalışan adı/soyadı istisnası (ayar: ai.allow_employee_names) bu sütunlara uygulanır.
    'employee_name_columns' => ['first_name', 'last_name', 'middle_name', 'full_name', 'display_name', 'default_full_name'],

];
