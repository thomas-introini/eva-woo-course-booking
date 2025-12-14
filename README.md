# Eva Course Bookings (Free)

Plugin WordPress + WooCommerce per la gestione di prenotazioni di corsi con date e orari.

## Funzionalità

- ✅ Converti prodotti WooCommerce esistenti in corsi prenotabili
- ✅ Gestione slot con data, ora e capacità massima
- ✅ Calendario interattivo per la selezione della data
- ✅ Prevenzione overselling con query atomiche
- ✅ Integrazione completa con carrello e checkout (Classic + Block)
- ✅ Email di conferma con dettagli del corso
- ✅ Pagina admin per gestione prenotazioni
- ✅ Compatibile con WooCommerce HPOS

## Requisiti

- WordPress 6.0+
- WooCommerce 7.0+
- PHP 8.0+

## Installazione

### Opzione 1: Installazione manuale

1. Scarica o clona questa repository
2. Copia la cartella `eva-course-bookings` in `wp-content/plugins/`
3. Attiva il plugin dal pannello WordPress

### Opzione 2: Ambiente di sviluppo Docker

Vedi la sezione [Sviluppo locale](#sviluppo-locale) sotto.

## Utilizzo

### Abilitare un prodotto come corso

1. Vai a **Prodotti → Modifica prodotto**
2. Nella sezione "Dati prodotto", spunta **"Abilita prenotazione corso"**
3. Salva il prodotto
4. Apparirà la sezione **"Date del corso"** dove puoi aggiungere gli slot

### Gestire gli slot

Nella sezione "Date del corso" del prodotto:

- **Aggiungi slot**: Data, ora inizio, ora fine (opzionale), capacità
- **Modifica**: Cambia data, ora o capacità
- **Chiudi/Apri**: Disabilita temporaneamente uno slot
- **Elimina**: Rimuovi uno slot (solo se non ha prenotazioni)

### Pagina admin prenotazioni

Vai a **WooCommerce → Prenotazioni corsi** per:

- Visualizzare tutti gli slot
- Filtrare per prodotto, stato o data
- Gestire slot da un'unica pagina

### Abilitazione in blocco

Vai a **WooCommerce → Abilita corsi** per abilitare la prenotazione su più prodotti contemporaneamente.

### Self-test

Vai a **WooCommerce → Eva Self Test** per verificare che il plugin sia configurato correttamente.

## Frontend

Quando un prodotto è abilitato come corso:

1. Il cliente vede un calendario con le date disponibili
2. Selezionando una data, appaiono gli orari disponibili
3. Dopo aver selezionato uno slot, può procedere all'acquisto
4. La quantità rappresenta il numero di partecipanti

## Sviluppo locale

### Prerequisiti

- Docker e Docker Compose installati
- Porta 8080 e 8081 disponibili

### Avvio

```bash
# Avvia l'ambiente
./bin/up.sh

# Attendi il completamento del setup automatico
```

### URL

| Servizio    | URL                           | Credenziali          |
|-------------|-------------------------------|----------------------|
| WordPress   | http://localhost:8080         | -                    |
| WP Admin    | http://localhost:8080/wp-admin| admin / admin        |
| phpMyAdmin  | http://localhost:8081         | wordpress / wordpress|

### Comandi disponibili

```bash
# Avvia ambiente
./bin/up.sh

# Ferma ambiente (mantiene i dati)
./bin/down.sh

# Reset completo (cancella tutti i dati)
./bin/reset.sh

# Visualizza log
./bin/logs.sh

# Log di un servizio specifico
./bin/logs.sh wordpress
./bin/logs.sh db
```

### Setup automatico

Lo script `up.sh` esegue automaticamente:

1. Avvio container Docker
2. Installazione WordPress
3. Installazione e configurazione WooCommerce
4. Creazione prodotti di esempio
5. Attivazione plugin Eva Course Bookings

### Prodotti di esempio

Vengono creati automaticamente tre prodotti:

1. **Corso di Fotografia Base** - €150.00
2. **Workshop di Cucina Italiana** - €89.00
3. **Corso di Yoga** - €120.00

### Testare il plugin

1. Vai a **Prodotti** nel pannello admin
2. Modifica uno dei prodotti di esempio
3. Spunta "Abilita prenotazione corso"
4. Aggiungi alcuni slot nella sezione "Date del corso"
5. Visualizza il prodotto sul frontend
6. Testa il flusso di acquisto

## Build

Per creare un pacchetto zip installabile:

```bash
./build.sh
```

Questo genera `eva-course-bookings.zip` pronto per l'upload su WordPress.

## Struttura del plugin

```
eva-course-bookings/
├── eva-course-bookings.php    # File principale
├── includes/
│   ├── class-plugin.php       # Classe principale
│   ├── class-admin.php        # Funzionalità admin
│   ├── class-frontend.php     # Funzionalità frontend
│   ├── class-slot-repository.php    # CRUD slot
│   └── class-woo-integration.php    # Integrazione WooCommerce
├── assets/
│   ├── css/
│   │   ├── admin.css
│   │   └── frontend.css
│   └── js/
│       ├── admin.js
│       └── frontend.js
└── templates/                 # (riservato per template futuri)
```

## Hook e filtri

### Azioni

```php
// Dopo la prenotazione di posti
do_action( 'eva_course_bookings_seats_reserved', $slot_id, $quantity, $order_id );

// Dopo il rilascio di posti
do_action( 'eva_course_bookings_seats_released', $slot_id, $quantity, $order_id );
```

### Filtri

```php
// Modifica date disponibili
add_filter( 'eva_course_bookings_available_dates', function( $dates, $product_id ) {
    return $dates;
}, 10, 2 );

// Modifica slot disponibili
add_filter( 'eva_course_bookings_available_slots', function( $slots, $product_id ) {
    return $slots;
}, 10, 2 );
```

## Note tecniche

### Prevenzione overselling

Il plugin usa query SQL atomiche per prevenire condizioni di race:

```sql
UPDATE postmeta pm1
INNER JOIN postmeta pm2 ON pm1.post_id = pm2.post_id
SET pm1.meta_value = pm1.meta_value + {qty}
WHERE pm1.post_id = {slot_id}
AND pm1.meta_key = '_eva_booked'
AND pm2.meta_key = '_eva_capacity'
AND (pm1.meta_value + {qty}) <= pm2.meta_value
```

### Compatibilità Block Checkout

Il plugin supporta sia il checkout classico che il nuovo Block Checkout di WooCommerce:

- Validazione carrello via `woocommerce_check_cart_items`
- Validazione checkout via `woocommerce_store_api_checkout_update_order_from_request`
- Persistenza dati slot nel carrello

### Caching

Per compatibilità con plugin di caching, gli slot vengono caricati via AJAX nel frontend.

## Changelog

### 1.0.0

- Release iniziale
- Gestione slot per prodotti
- Integrazione carrello e checkout
- Pannello admin prenotazioni
- Supporto Block Checkout

## Licenza

GPL v2 o successiva

## Supporto

Per bug e richieste di funzionalità, apri una issue su GitHub.

