document.addEventListener('DOMContentLoaded', () => {
    const chatBox = document.getElementById('chat-box');
    const userInput = document.getElementById('user-input');
    const sendBtn = document.getElementById('send-btn');
    const micBtn = document.getElementById('mic-btn');

    // --- Lógica de Reconhecimento de Voz ---
    const SpeechRecognition = window.SpeechRecognition || window.webkitSpeechRecognition;
    let recognition;

    if (SpeechRecognition) {
        recognition = new SpeechRecognition();
        recognition.lang = 'pt-BR';
        recognition.continuous = false;

        recognition.onstart = () => micBtn.classList.add('is-listening');
        recognition.onend = () => micBtn.classList.remove('is-listening');
        recognition.onerror = (event) => console.error('Erro no reconhecimento de voz:', event.error);
        
        recognition.onresult = (event) => {
            userInput.value = event.results[0][0].transcript;
            sendMessage();
        };

        micBtn.addEventListener('click', () => {
            if (micBtn.classList.contains('is-listening')) {
                recognition.stop();
            } else {
                recognition.start();
            }
        });
    } else {
        micBtn.style.display = 'none';
    }

    // --- Lógica de Envio de Mensagem ---
    sendBtn.addEventListener('click', sendMessage);
    userInput.addEventListener('keypress', (e) => {
        if (e.key === 'Enter') sendMessage();
    });

    function sendMessage() {
        const messageText = userInput.value.trim();
        if (messageText === '') return;

        displayMessage(messageText, 'user');
        userInput.value = '';

        fetch('chatbot_api.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ message: messageText })
        })
        .then(response => response.json())
        .then(data => {
            const reply = data.error ? `Erro: ${data.error}` : data.reply;
            displayMessage(reply, 'bot');
            
            // --- INÍCIO DA INTEGRAÇÃO DO ÁUDIO ---
            // Verifica se a resposta da API contém uma URL de áudio
            if (data.audio_url) {
                // Cria um novo elemento de áudio
                const audio = new Audio(data.audio_url);
                audio.play()
                    .catch(e => console.error('Erro ao reproduzir o áudio:', e));
            }
            // --- FIM DA INTEGRAÇÃO DO ÁUDIO ---
            
        })
        .catch(error => {
            console.error('Erro no fetch:', error);
            displayMessage('Desculpe, ocorreu um erro de comunicação.', 'bot');
        });
    }

    function displayMessage(text, sender) {
        const messageElement = document.createElement('div');
        messageElement.classList.add('message', `${sender}-message`);
        const p = document.createElement('p');
        p.textContent = text;
        messageElement.appendChild(p);
        chatBox.appendChild(messageElement);
        chatBox.scrollTop = chatBox.scrollHeight;
    }
    
    // As duas funções 'addMessage' e o código de troca de imagem
    // estão duplicados no seu arquivo. Você pode removê-los.
    // Mantenha apenas a função 'displayMessage' que você usa.
});