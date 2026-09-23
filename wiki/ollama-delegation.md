# Secondary Model Delegation Protocol (Ollama: Gemma4 Cloud / Qwen3 Local)

> Part of the [PressVitals Site Auditor LLM Wiki](index.md).

## 1. Protocol Overview & Hierarchy

To maximize efficiency, reduce latency, and preserve primary agent context, a mandatory (**"MUST"**) delegation protocol is established across the repository for all repetitive, mechanical, or low-risk tasks.

### Model Hierarchy & Quota Cascading:
1. **PRIMERO — `gemma4:cloud`**: Usar prioritariamente este modelo cloud siempre que exista suficiente cuota semanal disponible (< 90%).
2. **POSTERIORMENTE — `qwen3:8b-8k`**: Si la cuota semanal no es suficiente, o si la llamada a la nube falla por cualquier motivo, caer automáticamente al modelo local `qwen3:8b-8k` (~5.2 GB en memoria).

---

## 2. Technical Specifications & MCP Integration

### Cloud Model (`gemma4:cloud`)
- **Developer**: Google DeepMind (Gemma 4).
- **Scale**: ~31B parameters via Ollama cloud endpoint.
- **Quota Safeguard**: Evaluated automatically (<90% weekly usage threshold).

### Local Fallback Model (`qwen3:8b-8k`)
- **Developer**: Alibaba Cloud (Qwen Team).
- **Architecture**: Decoder-only dense transformer with **8.2 Billion parameters** (8.2B).
- **Quantization**: `Q4_K_M` GGUF (~5.22 GB active VRAM/RAM).
- **Context Window**: 8,192 tokens for fast, isolated inference (native up to 40,960).

### MCP Integration Details
- **Server Name**: `ollama-local`
- **Bridge Script**: `C:\Users\lenin\Documents\AIDevelopment\ollama\ollama_mcp_server.py`
- **Tool Name**: `consultar_modelo_local` (calls local endpoint at `http://localhost:11434/v1/chat/completions`).

---

## 3. Mandatory Delegation Matrix ("MUST")

All AI agents and skills operating within this repository **MUST** adhere strictly to this delegation matrix:

| Task Type | Action Required | Execution Tool |
| :--- | :---: | :--- |
| **Docstrings, comentarios y anotaciones de tipo** | **MUST DELEGATE** | `ollama-local` &rarr; `consultar_modelo_local` (`gemma4:cloud` &rarr; `qwen3:8b-8k`) |
| **Boilerplate repetitivo y formateo de estructuras** | **MUST DELEGATE** | `ollama-local` &rarr; `consultar_modelo_local` (`gemma4:cloud` &rarr; `qwen3:8b-8k`) |
| **Limpieza de Markdown, regex y parseo básico** | **MUST DELEGATE** | `ollama-local` &rarr; `consultar_modelo_local` (`gemma4:cloud` &rarr; `qwen3:8b-8k`) |
| **Traducciones y resúmenes mecánicos aislados** | **MUST DELEGATE** | `ollama-local` &rarr; `consultar_modelo_local` (`gemma4:cloud` &rarr; `qwen3:8b-8k`) |
| **Consultas puntuales sin contexto multi-archivo** | **MUST DELEGATE** | `ollama-local` &rarr; `consultar_modelo_local` (`gemma4:cloud` &rarr; `qwen3:8b-8k`) |
| **Decisiones de arquitectura y diseño de sistemas** | **DO NOT DELEGATE** | Primary Agent (Antigravity) |
| **Refactorizaciones que tocan múltiples archivos** | **DO NOT DELEGATE** | Primary Agent (Antigravity) |
| **Operaciones de Git, CI/CD y despliegues** | **DO NOT DELEGATE** | Primary Agent (Antigravity) |
| **Políticas de WordPress.org, seguridad y auditorías** | **DO NOT DELEGATE** | Primary Agent (Antigravity) |
