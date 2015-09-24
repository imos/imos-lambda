#include <stdio.h>
#include <stdlib.h>

int main(int argc, char** argv) {
  if (argc != 2) {
    fprintf(stderr, "Usage: prime count\n");
    return 1;
  }

  int prime_count = atoi(argv[1]);
  for (int i = 2;; i++) {
    bool is_prime = true;
    for (int j = 2; j * j <= i; j++) {
      if (i % j == 0) {
        is_prime = false;
        break;
      }
    }
    if (is_prime) {
      prime_count--;
      if (prime_count == 0) {
        printf("%d\n", i);
        return 0;
      }
    }
  }

  return 0;
}
