# CONTRATS D'API REST — Communication Inter-Apps (v5.0a3)

## Endpoints de l'Orchestrator (`http://orchestrator.ezstudio.local:8000`)

### 1. Enrôler un job (`POST /api/queue/push`)
- **Payload requis :**
  ```json
  {
    "source": "lab", 
    "payload": { "song_id": 47, "engine": "passt" }
  }
