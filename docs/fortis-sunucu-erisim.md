# Fortis sunucusu: erişim gereksinimleri

Fortis şirket ağındaki sunucuda, Kestra DigitalOcean'da (`68.183.66.112`) çalışacak.

## Fortis sunucusundan dışarı

| Hedef | Adres : port | Protokol |
|---|---|---|
| Anthropic (Claude) | `api.anthropic.com:443` | HTTPS |
| IBS veri ambarı | `10.184.210.52:18501` | MySQL (TCP) |
| APM | `apm-test01.gozen.systems:64070` (`172.31.68.9`) | HTTP |
| SAP SuccessFactors | `api55.sapsf.eu:443` | HTTPS |
| Kestra sunucusu | `68.183.66.112:22` | SSH (tünel, aşağıda) |
| Şirket SMTP relay | relay adresi, `:587` veya `:25` | SMTP |
| Microsoft Teams | `*.webhook.office.com:443` | HTTPS |
| Microsoft Entra (Microsoft ile giriş kullanılırsa) | `login.microsoftonline.com:443`, `graph.microsoft.com:443` | HTTPS |
| Hugging Face (sesli komut, sadece kurulumda) | `huggingface.co:443` | HTTPS |

## Fortis sunucusuna içeri

| Kaynak | Hedef | Protokol |
|---|---|---|
| Kullanıcılar (şirket ağı / VPN) | Fortis `:443` | HTTPS |
| Kestra | Fortis `/api/internal/*` (tünel üzerinden) | HTTP(S) |

## Kestra ↔ şirket ağı

Fortis sunucusundan Kestra'ya kalıcı bir SSH tüneli açılır (autossh + systemd). Kestra'nın şirket ağına başka bir erişimi gerekmez.

```sh
autossh -M 0 -N -o ServerAliveInterval=30 -o ExitOnForwardFailure=yes \
  -L 127.0.0.1:8080:127.0.0.1:8080 \          # Fortis → Kestra API
  -R 172.17.0.1:13306:10.184.210.52:18501 \   # Kestra → IBS
  -R 172.17.0.1:14070:172.31.68.9:64070 \     # Kestra → APM
  -R 172.17.0.1:18000:127.0.0.1:80 \          # Kestra → Fortis iç uç
  tunnel@68.183.66.112
```

- **Kestra sunucusu:** `sshd_config`'de `GatewayPorts clientspecified` olmalı.
- **Kestra'nın tünel dışında kendi dışarı erişimi:**
  - `api55.sapsf.eu:443` (SuccessFactors)
  - SMTP relay ve `*.webhook.office.com:443` (akışlardaki e-posta ve Teams adımları)
