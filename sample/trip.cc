#include <unistd.h>
#include <stdio.h>
#include <time.h>
#include <random>
using namespace std;

int main(int argc, char** argv) {
  if (argc != 2) {
    fprintf(stderr, "Usage: %s prefix\n", argv[0]);
    return 1;
  }

  char* prefix = argv[1];
  mt19937 mt((random_device())());
  char password[9] = {0};
  char salt[3] = {0};
  int start_time = time(nullptr);
  int count = 0;
  while (time(nullptr) < start_time + 10) {
    for (int loop = 0; loop < 1000; loop++) {
      for (int i = 0; i < 8; i++) {
        password[i] = 'a' + (mt() % 26);
      }
      salt[0] = password[1];
      salt[1] = password[2];
      char* trip = crypt(password, salt) + 3;
      bool mismatch = false;
      for (int i = 0; i < 8 && prefix[i] > 0; i++) {
        if (trip[i] != prefix[i]) {
          mismatch = true;
          break;
        }
      }
      if (!mismatch) {
        printf("%s %s\n", password, trip);
      }
      count++;
    }
  }
  fprintf(stderr, "Total: %d\n", count);

  return 0;
}
