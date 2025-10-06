echo "Hello! This script will install ollama, download all the AI models and programs needed and launch a local php script on localhost:3231."
echo "This will need at around 3.8GB, expect that the models need to be downloaded so that will cost almost the same amount in internet useage. So heavy costs could be charged. Im not responible for that in any way."
echo "Did you already installed the program? If yes, it will just start the server. (y/n)"
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
echo "Installing Ollama (1/6)"
sleep 1
curl -fsSL https://ollama.com/install.sh | sh
clear
echo "Installing model: gemma3:4b (2/6)"
sleep 1
ollama pull gemma3:4b
clear
echo "Installing model: gemma3:240m (3/6)"
sleep 1
ollama pull gemma3:270m
clear
echo "Installing php requirements (4/6)"
sleep 1
sudo apt update
sudo apt install php php-curl -y
clear
echo "Installing server (5/6)"
sleep 1
mkdir AI
cd AI
curl -o https://raw.githubusercontent.com/Niam3231/local-ai/main/index.php
clear
echo "Starting php server (6/6)"
echo "Go to localhost:3231 to talk to AI."
sleep 0.5
php -S 127.0.0.1:3231
fi
