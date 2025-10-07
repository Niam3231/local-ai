set -e
echo "Hello! This script will install ollama, download all the AI models and programs needed and launch a local php script on localhost:3231."
echo "This will need at around 5.2GB, expect that the models need to be downloaded so that will cost almost the same amount in internet useage. So heavy costs could be charged. Im not responible for that in any way."
echo "Did you already installed the program? If yes, it will just start the server in the ./AI folder. (y/n)"
read answer
if [ "$answer" = "y" ] || [ "$answer" = "Y" ]; then
clear
echo "Starting server"
echo "Go to localhost:3231 to talk to AI."
sleep 0.5
cd AI
php -S 127.0.0.1:3231
else
clear
echo "Installing Ollama (1/7)"
sleep 1
local_ver=$(command -v ollama >/dev/null 2>&1 && ollama --version | awk '{print $NF}' || echo none)
latest_ver=$(curl -fsSL https://api.github.com/repos/ollama/ollama/releases/latest | grep -Po '"tag_name": *"v?\K[^"]+')
if [ "$local_ver" = "none" ]; then
    echo "Installing Ollama..."
    curl -fsSL https://ollama.com/install.sh | sh
elif [ "$local_ver" != "$latest_ver" ]; then
    echo "Updating Ollama ($local_ver → $latest_ver)..."
    curl -fsSL https://ollama.com/install.sh | sh
else
    echo "Ollama is up to date ($local_ver)"
fi
clear
echo "Installing model: gemma3:4b (2/7)"
sleep 1
ollama pull gemma3:4b
clear
echo "Installing model: gemma3:270m (3/7)"
sleep 1
ollama pull gemma3:270m
clear
echo "Installing model: qwen3:1.7b (4/7)"
sleep 1
ollama pull qwen3:1.7b
clear
echo "Installing php requirements (5/7)"
sleep 1
sudo apt update
sudo apt install php php-curl -y
clear
echo "Installing server (6/7)"
sleep 1
mkdir AI
cd AI
curl -o index.php https://raw.githubusercontent.com/Niam3231/local-ai/main/index.php
clear
echo "Starting php server (7/7)"
echo "Go to localhost:3231 to talk to AI."
sleep 0.5
php -S 127.0.0.1:3231
fi
